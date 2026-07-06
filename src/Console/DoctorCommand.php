<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Transport\IngestCircuit;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Throwable;

/**
 * Diagnose every link of the shipping chain in one pass:
 *
 *   API key → ingest endpoint (authenticated ping) → buffer store →
 *   Cache::add debounce → queue connection → worker heuristic →
 *   ingest circuit → pending backlog.
 *
 * The transport swallows most of its failure modes on purpose (silent
 * store errors, swallowed queue dispatches, tripped circuit), so "no logs
 * arriving" is otherwise undebuggable without reading the package source.
 * Each check reports ok / warn / fail; the command exits non-zero only
 * when at least one check failed, so it is safe to wire into CI.
 */
class DoctorCommand extends Command
{
    protected $signature = 'owlogs:doctor
        {--reset-circuit : Close a tripped ingest circuit before running the checks}
        {--queue-timeout=5 : Seconds to wait for the queue worker probe before giving up}';

    protected $description = 'Check every link of the Owlogs shipping chain (API key, endpoint, buffer store, cache, queue, circuit).';

    private const DEFAULT_INGEST_URL = 'https://www.owlogs.com/api/owlogs/ingest';

    private const OK = 'ok';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    public function handle(): int
    {
        $this->line('Owlogs doctor — checking the shipping chain');
        $this->newLine();

        if ($this->option('reset-circuit') && IngestCircuit::isTripped()) {
            IngestCircuit::reset();
            $this->line('Ingest circuit was tripped — reset as requested.');
            $this->newLine();
        }

        /** @var array<string, callable(): array{0: string, 1: string}> $checks */
        $checks = [
            'agent_enabled' => fn (): array => $this->checkEnabled(),
            'api_key' => fn (): array => $this->checkApiKeyPresent(),
            'ingest_endpoint' => fn (): array => $this->checkIngestEndpoint(),
            'buffer_store' => fn (): array => $this->checkBufferStore(),
            'cache_debounce' => fn (): array => $this->checkCacheDebounce(),
            'queue_connection' => fn (): array => $this->checkQueueConnection(),
            'queue_worker' => fn (): array => $this->checkQueueWorker(),
            'ingest_circuit' => fn (): array => $this->checkIngestCircuit(),
            'pending_rows' => fn (): array => $this->checkPendingRows(),
        ];

        $counts = [self::OK => 0, self::WARN => 0, self::FAIL => 0];

        foreach ($checks as $name => $check) {
            try {
                [$status, $detail] = $check();
            } catch (Throwable $e) {
                [$status, $detail] = [self::FAIL, $e::class.': '.$e->getMessage()];
            }

            $counts[$status]++;

            $tag = match ($status) {
                self::OK => '<info>ok</info>  ',
                self::WARN => '<comment>warn</comment>',
                default => '<error>fail</error>',
            };

            $this->line(sprintf('%-18s %s  %s', $name, $tag, $detail));
        }

        $this->newLine();
        $this->line(sprintf('ok=%d warn=%d fail=%d', $counts[self::OK], $counts[self::WARN], $counts[self::FAIL]));
        $this->newLine();
        $this->line('Next: run `php artisan owlogs:emit-test-logs` and check that the logs reach your workspace, then re-run this doctor.');

        return $counts[self::FAIL] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkEnabled(): array
    {
        if (! config('owlogs.enabled', true)) {
            return [self::FAIL, 'agent disabled (OWLOGS_ENABLED=false) — nothing will ship'];
        }

        return [self::OK, 'owlogs.enabled = true'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkApiKeyPresent(): array
    {
        $key = (string) config('owlogs.api_key');

        if ($key === '') {
            return [self::FAIL, 'OWLOGS_API_KEY is empty — ship jobs abandon silently without it (run `php artisan owlogs:install --key=...`)'];
        }

        return [self::OK, 'present (…'.substr($key, -4).')'];
    }

    /**
     * Authenticated ping: POST an EMPTY batch to the ingest endpoint. The
     * auth middleware runs before payload validation server-side, so a 422
     * proves the key was accepted without ingesting any data; a 401 means
     * the key was rejected.
     *
     * @return array{0: string, 1: string}
     */
    private function checkIngestEndpoint(): array
    {
        $key = (string) config('owlogs.api_key');
        if ($key === '') {
            return [self::WARN, 'skipped — no API key to ping with'];
        }

        $url = $this->ingestUrl();

        try {
            $response = RemoteHandler::suppressedWhile(
                fn () => Http::withHeaders([
                    'X-Api-Key' => $key,
                    'Accept' => 'application/json',
                ])->timeout(10)->post($url, ['logs' => []]),
            );
        } catch (Throwable $e) {
            return [self::FAIL, "cannot reach {$url}: ".$e->getMessage()];
        }

        $status = $response->status();

        if ($response->successful() || $status === 422) {
            return [self::OK, "key accepted by {$url}"];
        }

        return match ($status) {
            401 => [self::FAIL, 'API key rejected (401) — check OWLOGS_API_KEY against your workspace API keys'],
            403 => [self::FAIL, 'key valid but subscription inactive (403) — logs will be refused until the plan is fixed'],
            429 => [self::WARN, 'rate limited (429) — quota exhausted or too many failed auth attempts, retry later'],
            default => [self::FAIL, "unexpected status {$status} from {$url}"],
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkBufferStore(): array
    {
        $driver = (string) config('owlogs.transport.buffer_store', 'redis');

        if ($driver === 'memory') {
            return [self::WARN, 'memory store is process-local — the queue worker cannot see buffered rows (testing only)'];
        }

        if ($driver === 'file') {
            $path = (string) (config('owlogs.transport.file_path') ?: storage_path('app/owlogs/buffer.jsonl'));
            $dir = dirname($path);

            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            if (! is_dir($dir) || ! is_writable($dir)) {
                return [self::FAIL, "buffer directory '{$dir}' is not writable"];
            }

            if (is_file($path) && ! is_writable($path)) {
                return [self::FAIL, "buffer file '{$path}' is not writable"];
            }

            return [self::OK, "file buffer at '{$path}' is writable"];
        }

        if ($driver !== 'redis') {
            return [self::FAIL, "unknown buffer_store '{$driver}' — expected redis, file or memory"];
        }

        $connection = (string) config('owlogs.transport.redis_connection', 'default');
        $listKey = (string) config('owlogs.transport.redis_key', 'owlogs:buffer');

        try {
            Redis::connection($connection)->ping();
        } catch (Throwable $e) {
            return [self::FAIL, "redis connection '{$connection}' unreachable: ".$e->getMessage()];
        }

        return [self::OK, "redis list '{$listKey}' reachable on connection '{$connection}'"];
    }

    /**
     * The ship debounce marker and the ingest circuit both live in Cache.
     * A per-process driver (array/null) still "works" but silently degrades:
     * every process re-arms its own debounce and never sees a circuit
     * tripped elsewhere.
     *
     * @return array{0: string, 1: string}
     */
    private function checkCacheDebounce(): array
    {
        $driver = (string) config('cache.default');
        $probe = 'owlogs:doctor:probe:'.Str::random(8);

        try {
            $added = Cache::add($probe, 1, 30);
            $readable = Cache::get($probe) !== null;
            Cache::forget($probe);
        } catch (Throwable $e) {
            return [self::FAIL, "Cache::add probe failed on driver '{$driver}': ".$e->getMessage()];
        }

        if (! $added || ! $readable) {
            return [self::FAIL, "Cache::add round-trip failed on driver '{$driver}' — the ship debounce cannot work"];
        }

        if (in_array($driver, ['array', 'null'], true)) {
            return [self::WARN, "cache driver '{$driver}' is process-local — ship debounce and circuit breaker will not be shared with workers"];
        }

        return [self::OK, "driver '{$driver}' supports the Cache::add debounce"];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkQueueConnection(): array
    {
        [$connection, $queue] = $this->shipConnectionAndQueue();
        $driver = config("queue.connections.{$connection}.driver");

        if (! is_string($driver) || $driver === '') {
            return [self::FAIL, "queue connection '{$connection}' is not defined in config/queue.php"];
        }

        if ($driver === 'sync') {
            return [self::WARN, "connection '{$connection}' uses the sync driver — logs ship inline, adding HTTP latency to every request"];
        }

        return [self::OK, "connection '{$connection}' (driver {$driver}), queue '{$queue}'"];
    }

    /**
     * Heuristic worker check: dispatch a probe closure onto the ship queue
     * and poll for the cache flag it sets. Requires a cross-process cache
     * to be meaningful — with a process-local cache the flag set by the
     * worker is invisible here, so the check degrades to a warning.
     *
     * @return array{0: string, 1: string}
     */
    private function checkQueueWorker(): array
    {
        [$connection, $queue] = $this->shipConnectionAndQueue();
        $driver = (string) config("queue.connections.{$connection}.driver", '');

        if ($driver === 'sync') {
            return [self::OK, 'sync driver runs jobs inline — no worker needed'];
        }

        $cacheDriver = (string) config('cache.default');
        if (in_array($cacheDriver, ['array', 'null'], true)) {
            return [self::WARN, "cannot verify: cache driver '{$cacheDriver}' is process-local, a worker's probe flag would be invisible here"];
        }

        $flag = 'owlogs:doctor:worker:'.Str::random(8);

        try {
            dispatch(function () use ($flag): void {
                Cache::put($flag, 1, 120);
            })->onConnection($connection)->onQueue($queue);
        } catch (Throwable $e) {
            return [self::FAIL, "probe dispatch to connection '{$connection}' failed: ".$e->getMessage()];
        }

        $timeoutS = max(0, (int) $this->option('queue-timeout'));
        $startedAt = microtime(true);
        $deadline = $startedAt + $timeoutS;

        while (true) {
            try {
                if (Cache::get($flag) !== null) {
                    Cache::forget($flag);
                    $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

                    return [self::OK, "worker consumed the probe from queue '{$queue}' in {$elapsedMs}ms"];
                }
            } catch (Throwable) {
                // Cache hiccup mid-poll — keep trying until the deadline.
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            usleep(150_000);
        }

        return [self::WARN, "no worker picked up the probe on queue '{$queue}' (connection '{$connection}') within {$timeoutS}s — is `queue:work` / Horizon consuming it?"];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkIngestCircuit(): array
    {
        if (! config('owlogs.transport.circuit.enabled', true)) {
            return [self::OK, 'circuit breaker disabled (OWLOGS_CIRCUIT_ENABLED=false)'];
        }

        if (! IngestCircuit::isTripped()) {
            return [self::OK, 'closed — shipments flow normally'];
        }

        $state = IngestCircuit::state();
        $detail = $state !== null
            ? sprintf('status %d, reason %s, tripped at %s', $state['status'], $state['reason'] ?? 'unknown', $state['tripped_at'] ?? 'unknown')
            : 'no trip details available';

        $cooldown = (int) config('owlogs.transport.circuit.cooldown_s', 300);

        return [self::FAIL, "tripped ({$detail}) — new logs are dropped until it re-arms (~{$cooldown}s); run `owlogs:doctor --reset-circuit` to close it now"];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkPendingRows(): array
    {
        $size = app(LogBufferStore::class)->size();

        if ($size === 0) {
            return [self::OK, 'buffer empty — nothing waiting to ship'];
        }

        return [self::OK, "{$size} buffered row(s) pending — a ship job should drain them within the debounce window"];
    }

    private function ingestUrl(): string
    {
        return (string) (config('owlogs.transport.ingest_url') ?: self::DEFAULT_INGEST_URL);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function shipConnectionAndQueue(): array
    {
        $connection = (string) (config('owlogs.transport.connection') ?: config('queue.default'));
        $queue = (string) config('owlogs.transport.queue', 'default');

        return [$connection, $queue];
    }
}
