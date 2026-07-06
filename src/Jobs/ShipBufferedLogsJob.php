<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Transport\IngestCircuit;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Throwable;

/**
 * Internal signal raised by {@see ShipBufferedLogsJob::sendChunk()} when a
 * transient failure occurred but we have retries left. Caught at the
 * boundary of {@see ShipBufferedLogsJob::handle()} so the current handle()
 * call returns cleanly after `$this->release($delay)` has been issued,
 * without surfacing as a fatal exception in Horizon / the queue worker
 * log. Only the final attempt re-throws the original exception so
 * Laravel's `failed()` hook fires.
 */
final class ShipBufferedLogsRetrying extends \RuntimeException {}

/**
 * Drains up to `transport.ship.batch_count` log rows from the
 * LogBufferStore, ships them to the Owlogs ingest endpoint, and
 * self-re-dispatches (without delay) while there is still pending data.
 *
 * Debounce is enforced at dispatch time by a `Cache::add` marker in
 * RemoteHandler::flush() — multiple flushes within the debounce window
 * collapse to a single queued job. This job releases that marker
 * (Cache::pull) in handle() so a subsequent flush can re-arm it.
 *
 * Retries (3 × [5s, 30s, 120s]) apply to the HTTP POST — rows already
 * drained from the store are held in the job payload, so a retry ships
 * the same batch again (at-least-once, never lost on transient 5xx).
 */
class ShipBufferedLogsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public const PENDING_CACHE_KEY = 'owlogs:ship:pending';

    private const DEFAULT_INGEST_URL = 'https://www.owlogs.com/api/owlogs/ingest';

    public function __construct()
    {
        $this->onQueue((string) config('owlogs.transport.queue', 'default'));

        $connection = config('owlogs.transport.connection');
        if ($connection !== null) {
            $this->onConnection((string) $connection);
        }
    }

    public function handle(LogBufferStore $store): void
    {
        // Release the debounce marker as soon as we start — new flushes
        // during our work must be allowed to re-arm the next ship.
        Cache::forget(self::PENDING_CACHE_KEY);

        try {
            $this->runShip($store);
        } catch (ShipBufferedLogsRetrying) {
            // Transient failure on a non-final attempt: $this->release()
            // already queued a retry with the right backoff and the
            // failure has been logged as a warning. Returning cleanly
            // here keeps the queue worker quiet — only the final attempt
            // surfaces as a job failure (failed() hook + Horizon entry).
        }
    }

    private function runShip(LogBufferStore $store): void
    {
        // No work emitted from within this job may reach the owlogs
        // channel: any Log::* here would buffer → spawn another ship
        // job → loop.
        RemoteHandler::suppressedWhile(function () use ($store): void {
            // Circuit tripped (a previous ship hit 403/429 and the
            // cooldown is still in flight): exit WITHOUT touching the
            // spool. Retained rows (error/critical buffered during the
            // cooldown plus the pre-trip backlog) survive within the
            // FIFO cap and ship once the circuit re-arms; the max-age
            // filter bounds how stale a post-outage burst can get.
            if (IngestCircuit::isTripped()) {
                return;
            }

            $batchCount = (int) config('owlogs.transport.ship.batch_count', 256);

            $rows = $this->dropStaleRows($store->drain($batchCount));

            // Outage over: prepend ONE synthetic diagnostic row carrying the
            // drop tallies accumulated while the circuit was tripped, so the
            // gap is visible server-side instead of silently swallowed.
            $dropped = IngestCircuit::claimDropped();
            if ($dropped !== []) {
                array_unshift($rows, $this->buildDropReportRow($dropped));
            }

            if ($rows === []) {
                if ($store->size() > 0) {
                    $this->scheduleFollowUp();
                }

                return;
            }

            $this->sendAll($rows, $store);

            if ($store->size() > 0 && ! IngestCircuit::isTripped()) {
                $this->scheduleFollowUp();
            }
        });
    }

    /**
     * Filter out rows whose timestamp is older than the configured
     * `buffer.max_age_s` window. Protects the server from a burst of
     * stale data when shipments resume after a long stall (queue worker
     * outage, network blip) and bounds the value of any data we still
     * try to ship.
     *
     * Collapsed rows (dedup `count` > 1) are judged by `last_at` — their
     * `logged_at` is the FIRST occurrence, but the row is fresh as long
     * as its latest occurrence is.
     *
     * Drop is silent — count goes into a single summary log on the
     * local `single` channel (owlogs is suppressed inside handle()).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dropStaleRows(array $rows): array
    {
        $maxAgeS = $this->effectiveMaxAgeS();
        if ($maxAgeS <= 0 || $rows === []) {
            return $rows;
        }

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-'.$maxAgeS.' seconds');

        $kept = [];
        $dropped = 0;

        foreach ($rows as $row) {
            $loggedAt = $row['last_at'] ?? $row['logged_at'] ?? null;

            if (! is_string($loggedAt) || $loggedAt === '') {
                $kept[] = $row;

                continue;
            }

            $when = \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i:s.v',
                $loggedAt,
                new \DateTimeZone('UTC'),
            );

            // Unknown format — keep the row rather than silently lose it.
            if ($when === false) {
                $kept[] = $row;

                continue;
            }

            if ($when < $cutoff) {
                $dropped++;

                continue;
            }

            $kept[] = $row;
        }

        if ($dropped > 0) {
            // Tallied into the drop-episode counters so the synthetic
            // diagnostic row reports the gap once shipments recover.
            IngestCircuit::recordDropped('stale', $dropped);

            try {
                logger()->channel('single')->warning(
                    'Owlogs dropped stale buffered logs',
                    [
                        'dropped' => $dropped,
                        'kept' => count($kept),
                        'max_age_s' => $maxAgeS,
                    ],
                );
            } catch (Throwable) {
                // best-effort
            }
        }

        return $kept;
    }

    /**
     * Stale cutoff for the current drain. Inside an outage/recovery window
     * (circuit tripped, or its cooldown recently elapsed) the wider
     * `buffer.retry_max_age_s` applies so the backlog retained through the
     * cooldown is not wiped as "too old" on the first post-outage ship.
     */
    private function effectiveMaxAgeS(): int
    {
        $maxAgeS = (int) config('owlogs.transport.buffer.max_age_s', 0);

        if (! IngestCircuit::inRecoveryWindow()) {
            return $maxAgeS;
        }

        return max($maxAgeS, (int) config('owlogs.transport.buffer.retry_max_age_s', 600));
    }

    /**
     * Synthetic diagnostic row summarising an outage/drop episode. Built by
     * hand (never through Monolog) so it cannot re-enter the handler while
     * RemoteHandler::suppressedWhile() is active — no feedback loop.
     *
     * @param  array<string, int>  $dropped  reason → dropped row count
     * @return array<string, mixed>
     */
    private function buildDropReportRow(array $dropped): array
    {
        $total = array_sum($dropped);
        $context = [
            'dropped' => $dropped,
            'total' => $total,
            'circuit' => IngestCircuit::state(),
        ];

        return [
            'message' => "owlogs.agent.dropped_rows: {$total} log rows were dropped client-side during an ingest outage",
            'level' => 300,
            'level_name' => 'WARNING',
            'channel' => 'owlogs',
            'origin' => 'internal',
            'context' => json_encode($context, JSON_UNESCAPED_UNICODE) ?: null,
            'logged_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.v'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function sendAll(array $rows, LogBufferStore $store): void
    {
        $apiKey = (string) config('owlogs.api_key');
        if ($apiKey === '') {
            // No API key configured — abandon quietly (useful for local dev).
            return;
        }

        $maxBytes = (int) config('owlogs.transport.max_payload_bytes', 512 * 1024);

        $chunks = $this->chunkBySize($rows, $maxBytes);

        foreach ($chunks as $index => $chunk) {
            // If a previous chunk in this job tripped the circuit, stop
            // iterating — requeue the unsent remainder so it survives the
            // cooldown (FIFO cap + stale filter bound the backlog) instead
            // of POSTing more doomed chunks or wiping the spool.
            if (IngestCircuit::isTripped()) {
                $this->requeueChunks(array_slice($chunks, $index), $store);

                return;
            }

            $this->sendChunk($chunk, $apiKey, $store);
        }
    }

    /**
     * Push unsent chunks back onto the cross-process store so the backlog
     * survives a circuit trip. Best-effort — the store enforces its FIFO
     * `max_rows` cap, and stale rows are aged out on the next drain.
     *
     * @param  list<list<array<string, mixed>>>  $chunks
     */
    private function requeueChunks(array $chunks, LogBufferStore $store): void
    {
        if ($chunks === []) {
            return;
        }

        $rows = array_merge(...$chunks);
        if ($rows === []) {
            return;
        }

        try {
            $store->append($rows);
        } catch (Throwable) {
            // Store unavailable — nothing more we can do; the drop stays
            // invisible only until the next episode report.
        }
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     */
    private function sendChunk(array $chunk, string $apiKey, LogBufferStore $store): void
    {
        $timeout = (int) config('owlogs.transport.timeout_s', 30);
        $compression = (bool) config('owlogs.transport.compression', true);
        $url = (string) (config('owlogs.transport.ingest_url') ?: self::DEFAULT_INGEST_URL);

        $headers = [
            'X-Api-Key' => $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'owlogs-agent/1.0',
        ];

        $body = json_encode(['logs' => $chunk], JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return;
        }

        if ($compression) {
            $body = gzencode($body, 6);
            $headers['Content-Encoding'] = 'gzip';
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout($timeout)
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                return;
            }

            $status = $response->status();

            // 403 (no plan) is fatal at the tenant level and needs a human
            // to fix (subscribe) — retrying just burns CPU for the same
            // verdict. Trip the circuit and wipe the backlog: nothing will
            // be accepted until the plan changes.
            if ($status === 403) {
                IngestCircuit::trip(403, 'subscription_required');
                $store->clear();
                $this->fail(new \RuntimeException('Owlogs subscription required: '.$response->body()));

                return;
            }

            // 429 (quota exhausted) is temporary — the server sheds
            // low-severity rows but keeps accepting error/critical on a
            // grace budget, and quota resets monthly. Trip the circuit but
            // KEEP the spool: the failed chunk goes back onto the store and
            // ships once the cooldown elapses, bounded by the FIFO cap and
            // the `buffer.retry_max_age_s` stale filter.
            if ($status === 429) {
                IngestCircuit::trip(429, 'quota_exhausted');
                $this->requeueChunks([$chunk], $store);
                $this->fail(new \RuntimeException('Owlogs quota exhausted: '.$response->body()));

                return;
            }

            if ($status >= 400 && $status < 500) {
                $this->fail(new \RuntimeException("Owlogs ingestion rejected with status {$status}: ".$response->body()));

                return;
            }

            throw new \RuntimeException("Owlogs ingestion failed with status {$status}");
        } catch (Throwable $e) {
            $this->handleTransientFailure($e);
        }
    }

    /**
     * Handle a transient HTTP failure (network blip, DNS resolution failure,
     * 5xx response). On any attempt that is NOT the final one we silently
     * release the job back to the queue with the configured backoff and emit
     * a single warning line — so the queue worker / Horizon doesn't surface
     * an "exception" for every retry. Only when we've burned through
     * `$tries` does the original exception bubble up so Laravel marks the
     * job as failed and invokes {@see self::failed()}.
     */
    private function handleTransientFailure(Throwable $e): void
    {
        if ($this->attempts() >= $this->tries) {
            // Last attempt — let it propagate so failed() fires.
            throw $e;
        }

        $delay = $this->backoff[$this->attempts() - 1] ?? 60;

        try {
            logger()->channel('single')->warning(
                sprintf(
                    'Owlogs ShipBufferedLogsJob attempt %d/%d failed, retrying in %ds',
                    $this->attempts(),
                    $this->tries,
                    $delay,
                ),
                ['error' => $e->getMessage()],
            );
        } catch (Throwable) {
            // best-effort
        }

        $this->release($delay);

        // Signal the outer handle() to return cleanly without surfacing
        // the original exception as a worker-level failure.
        throw new ShipBufferedLogsRetrying;
    }

    /**
     * Split rows into chunks whose JSON size stays below $maxBytes.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    private function chunkBySize(array $rows, int $maxBytes): array
    {
        $chunks = [];
        $current = [];
        $currentBytes = 0;

        foreach ($rows as $row) {
            $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
            $size = $encoded !== false ? strlen($encoded) : 0;

            if ($current !== [] && $currentBytes + $size > $maxBytes) {
                $chunks[] = $current;
                $current = [];
                $currentBytes = 0;
            }

            $current[] = $row;
            $currentBytes += $size;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Dispatch a fresh ship job to drain the remainder. Uses Cache::add
     * as a guard so that a concurrent flush that also detected pending
     * data only produces one follow-up, not many.
     */
    private function scheduleFollowUp(): void
    {
        try {
            $acquired = Cache::add(self::PENDING_CACHE_KEY, 1, now()->addSeconds(60));
            if (! $acquired) {
                return;
            }
        } catch (Throwable) {
            // If cache is unavailable, fall through and dispatch anyway —
            // worst case we spawn one extra ship, the store's atomic
            // drain still prevents duplicate shipments.
        }

        try {
            self::dispatch();
        } catch (Throwable) {
            // Silent: queue backend may be unavailable.
        }
    }

    public function failed(Throwable $exception): void
    {
        RemoteHandler::suppressedWhile(function () use ($exception): void {
            try {
                logger()->channel('single')->warning(
                    'Owlogs ShipBufferedLogsJob failed permanently',
                    [
                        'error' => $exception->getMessage(),
                    ]
                );
            } catch (Throwable) {
                // swallow
            }
        });
    }
}
