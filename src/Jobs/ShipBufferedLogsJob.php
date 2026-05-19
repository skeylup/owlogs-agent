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

        // No work emitted from within this job may reach the owlogs
        // channel: any Log::* here would buffer → spawn another ship
        // job → loop.
        RemoteHandler::suppressedWhile(function () use ($store): void {
            // Circuit tripped (a previous ship hit 403/429 and the
            // cooldown is still in flight): wipe the backlog and exit.
            // Any rows in flight are guaranteed to fail with the same
            // status — better to drop them now than to keep the queue
            // busy and the client's buffer growing.
            if (IngestCircuit::isTripped()) {
                $store->clear();

                return;
            }

            $batchCount = (int) config('owlogs.transport.ship.batch_count', 256);

            $rows = $store->drain($batchCount);
            if ($rows === []) {
                return;
            }

            $rows = $this->dropStaleRows($rows);
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
     * Filter out rows whose `logged_at` timestamp is older than the
     * configured `buffer.max_age_s` window. Protects the server from a
     * burst of stale data when shipments resume after a long stall
     * (queue worker outage, network blip) and bounds the value of any
     * data we still try to ship.
     *
     * Drop is silent — count goes into a single summary log on the
     * local `single` channel (owlogs is suppressed inside handle()).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function dropStaleRows(array $rows): array
    {
        $maxAgeS = (int) config('owlogs.transport.buffer.max_age_s', 0);
        if ($maxAgeS <= 0) {
            return $rows;
        }

        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-'.$maxAgeS.' seconds');

        $kept = [];
        $dropped = 0;

        foreach ($rows as $row) {
            $loggedAt = $row['logged_at'] ?? null;

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

        foreach ($this->chunkBySize($rows, $maxBytes) as $chunk) {
            // If a previous chunk in this job tripped the circuit,
            // stop iterating — clear the store and exit before we try
            // to POST another doomed chunk.
            if (IngestCircuit::isTripped()) {
                $store->clear();

                return;
            }

            $this->sendChunk($chunk, $apiKey, $store);
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

            // 403 (no plan) and 429 (quota exhausted) are fatal at the
            // tenant level — retrying just burns CPU for the same
            // verdict. Trip the circuit so further records get dropped
            // on the floor for the configured cooldown, wipe the
            // backlog so we don't carry it through, and fail() so this
            // job stops retrying.
            if ($status === 403) {
                IngestCircuit::trip(403, 'subscription_required');
                $store->clear();
                $this->fail(new \RuntimeException('Owlogs subscription required: '.$response->body()));

                return;
            }

            if ($status === 429) {
                IngestCircuit::trip(429, 'quota_exhausted');
                $store->clear();
                $this->fail(new \RuntimeException('Owlogs quota exhausted: '.$response->body()));

                return;
            }

            if ($status >= 400 && $status < 500) {
                $this->fail(new \RuntimeException("Owlogs ingestion rejected with status {$status}: ".$response->body()));

                return;
            }

            throw new \RuntimeException("Owlogs ingestion failed with status {$status}");
        } catch (Throwable $e) {
            throw $e;
        }
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
