<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Contracts\HasLogContext;
use Skeylup\OwlogsAgent\Flushing\EndOfRequestPolicy;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Throwable;

/**
 * Monolog handler. Two responsibilities only: (1) buffer incoming log
 * records in RAM respecting the injected FlushPolicy; (2) on flush,
 * push the accumulated rows to the cross-process LogBufferStore and
 * debounce-dispatch a ShipBufferedLogsJob that will actually POST them
 * to Owlogs.
 *
 * We no longer dispatch one SendLogsJob per flush: N flushes within
 * `transport.ship.debounce_ms` collapse to a single queued ship job
 * via a Cache::add marker. The ship job drains up to
 * `transport.ship.batch_count` rows from the store, sends them, then
 * re-dispatches itself while `store->size() > 0`.
 */
class RemoteHandler extends AbstractProcessingHandler
{
    private int $batchSize;

    private int $maxPayloadBytes;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private int $bufferBytes = 0;

    private ?float $firstBufferedAt = null;

    private bool $shutdownRegistered = false;

    private bool $flushing = false;

    private FlushPolicy $policy;

    private LogBufferStore $store;

    /**
     * When true, every incoming record is dropped on the floor instead of
     * being buffered. Used by the internal owlogs jobs (SendLogsJob,
     * IngestLogsJob, GenerateLogEmbeddingsJob) to break feedback loops:
     *
     *   owlogs job → Log::* / exception → owlogs channel → buffered
     *   → new SendLogsJob → same failure → loop.
     *
     * Scope is the current PHP process; queue workers serialize their job
     * execution so the flag is effectively job-scoped when toggled via
     * suppressedWhile().
     */
    public static bool $suppressed = false;

    /**
     * Execute $callback with owlogs buffering fully disabled. Any Log::* or
     * exception that would normally reach this handler is silently dropped
     * for the duration. Nested calls are safe — the previous state is
     * restored on exit.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function suppressedWhile(callable $callback): mixed
    {
        $previous = self::$suppressed;
        self::$suppressed = true;

        try {
            return $callback();
        } finally {
            self::$suppressed = $previous;
        }
    }

    public function __construct(
        Level|int|string $level = Level::Debug,
        bool $bubble = true,
        ?FlushPolicy $policy = null,
        ?LogBufferStore $store = null,
    ) {
        parent::__construct($level, $bubble);

        $this->batchSize = (int) config('owlogs.transport.batch_size', 50);
        $this->maxPayloadBytes = (int) config('owlogs.transport.max_payload_bytes', 512 * 1024);
        $this->policy = $policy ?? new EndOfRequestPolicy;
        $this->store = $store ?? new InMemoryLogBufferStore;
    }

    public function setPolicy(FlushPolicy $policy): void
    {
        $this->policy = $policy;
    }

    public function setStore(LogBufferStore $store): void
    {
        $this->store = $store;
    }

    public function bufferCount(): int
    {
        return count($this->buffer);
    }

    public function bufferBytes(): int
    {
        return $this->bufferBytes;
    }

    public function firstBufferedAt(): ?float
    {
        return $this->firstBufferedAt;
    }

    protected function write(LogRecord $record): void
    {
        if (self::$suppressed) {
            return;
        }

        $row = $this->buildRow($record);
        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
        $this->bufferBytes += $encoded !== false ? strlen($encoded) : 0;
        $this->buffer[] = $row;

        if ($this->firstBufferedAt === null) {
            $this->firstBufferedAt = microtime(true);
        }

        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(function (): void {
                $this->flush(true);
            });
        }

        // Hard ceiling: bypass any policy when the buffer grows unreasonably.
        // Protects against a runaway caller logging faster than we can ship
        // (same risk in FPM or under Octane — memory is the only constraint).
        $hardCount = $this->batchSize * 2;
        $hardBytes = $this->maxPayloadBytes * 2;
        if (count($this->buffer) >= $hardCount || $this->bufferBytes >= $hardBytes) {
            $this->flush(true);

            return;
        }

        $this->policy->onWrite($this);
    }

    /**
     * Flush: drain the RAM buffer into the cross-process LogBufferStore
     * and debounce-dispatch a ShipBufferedLogsJob. Attaches the current
     * measures snapshot and memory peak to the last row of the batch.
     *
     * The first flush in each debounce window dispatches the ship job
     * (with delay = debounce_ms). Subsequent flushes during that window
     * append to the store without dispatching — the single queued ship
     * job drains everything at once.
     *
     * @param  bool  $force  reserved for future use; flush is always unconditional now
     */
    public function flush(bool $force = false): void
    {
        if ($this->buffer === []) {
            return;
        }

        if ($this->flushing) {
            // Reentrant call (e.g. Octane::tick fires while onWrite is flushing).
            return;
        }

        $this->flushing = true;

        try {
            $rows = $this->buffer;
            $this->buffer = [];
            $this->bufferBytes = 0;
            $this->firstBufferedAt = null;

            // Attach measures + memory to the last entry of the batch
            $lastIdx = count($rows) - 1;
            $measures = Context::get('measures');
            if ($measures !== null) {
                $rows[$lastIdx]['measures'] = json_encode($measures, JSON_UNESCAPED_UNICODE);
            }
            if (config('owlogs.measure.memory', true)) {
                $rows[$lastIdx]['memory_peak_mb'] = (int) round(memory_get_peak_usage(true) / 1024 / 1024);
            }

            try {
                $this->store->append($rows);
            } catch (Throwable) {
                // Silent failure: store may be momentarily unavailable.
                return;
            }

            $this->scheduleShip();
        } finally {
            $this->flushing = false;
        }
    }

    public function close(): void
    {
        $this->flush(true);
        parent::close();
    }

    /**
     * Dispatch a ShipBufferedLogsJob at most once per debounce window.
     *
     * Uses `Cache::add` as an atomic "first in the window wins" guard.
     * If the marker already exists, another flush has already queued a
     * ship job that will drain whatever we just appended.
     */
    private function scheduleShip(): void
    {
        $debounceMs = (int) config('owlogs.transport.ship.debounce_ms', 10000);
        $debounceSec = max(1, (int) ceil($debounceMs / 1000));
        // Keep the marker alive well past the delay so late arrivals
        // within the same window still see it; the ship job itself
        // releases it as soon as it starts handling.
        $markerTtl = $debounceSec + 60;

        try {
            $acquired = Cache::add(
                ShipBufferedLogsJob::PENDING_CACHE_KEY,
                1,
                now()->addSeconds($markerTtl),
            );
        } catch (Throwable) {
            // Cache backend unavailable — dispatch anyway. Worst case
            // we queue a few redundant ship jobs; the store's atomic
            // drain still prevents duplicate shipments.
            $acquired = true;
        }

        if (! $acquired) {
            return;
        }

        try {
            ShipBufferedLogsJob::dispatch()->delay(now()->addSeconds($debounceSec));
        } catch (Throwable) {
            // Silent: queue backend may be unavailable.
            // The records stay in the store and will be shipped by a
            // later ship job (next flush re-arms the marker when the
            // cache TTL expires).
        }
    }

    /**
     * Build a database-ready row from a Monolog record.
     *
     * @return array<string, mixed>
     */
    private function buildRow(LogRecord $record): array
    {
        $contextData = Context::all();
        $extra = $record->extra;
        $userContext = $record->context;

        // Stacktrace from exception
        $stacktrace = null;
        if (isset($userContext['exception']) && $userContext['exception'] instanceof Throwable) {
            $stacktrace = $this->formatException($userContext['exception']);
            unset($userContext['exception']);
        }

        // Transform models implementing HasLogContext into their safe representation
        $userContext = $this->transformContext($userContext);

        return [
            'trace_id' => $contextData['trace_id'] ?? null,
            'span_id' => $contextData['span_id'] ?? null,
            'origin' => $contextData['origin'] ?? null,

            'level_name' => $record->level->getName(),
            'level' => $record->level->value,
            'channel' => $record->channel,
            'message' => $record->message,
            'stacktrace' => $stacktrace,

            'caller_file' => $extra['caller_file'] ?? null,
            'caller_line' => $extra['caller_line'] ?? null,
            'caller_method' => $extra['caller_method'] ?? null,

            'uri' => $this->truncate($contextData['uri'] ?? null, 2048),
            'route_name' => $contextData['route_name'] ?? null,
            'route_action' => $this->truncate($contextData['route_action'] ?? null, 512),
            'ip' => $contextData['ip'] ?? null,
            'user_agent' => $this->truncate($contextData['user_agent'] ?? null, 512),
            'request_input' => $contextData['request_input'] ?? null,

            // Resolved once per request/job by AddLogContext (HTTP), the queue
            // hydrated listener, or the CommandStarting listener. Never call
            // auth()->id() here — buildRow() runs once per log record, which
            // on a busy request can be dozens of calls that each re-resolve
            // the guard, user provider and (worst case) hit the DB.
            'user_id' => $contextData['user_id'] ?? null,

            'app_name' => $contextData['app_name'] ?? null,
            'app_env' => $contextData['app_env'] ?? null,
            'app_url' => $contextData['app_url'] ?? null,
            'git_sha' => $contextData['git_sha'] ?? null,

            'job_class' => $contextData['job_class'] ?? null,
            'job_attempt' => $contextData['job_attempt'] ?? null,
            'queue_name' => $contextData['queue_name'] ?? null,
            'connection_name' => $contextData['connection_name'] ?? null,

            'duration_ms' => $contextData['duration_ms'] ?? null,

            'context' => ! empty($userContext) ? json_encode($userContext, JSON_UNESCAPED_UNICODE) : null,
            'breadcrumbs' => isset($contextData['breadcrumbs']) ? json_encode($contextData['breadcrumbs'], JSON_UNESCAPED_UNICODE) : null,
            'job_props' => isset($contextData['job_props']) ? json_encode($contextData['job_props'], JSON_UNESCAPED_UNICODE) : null,
            'measures' => null,
            'memory_peak_mb' => null,
            'extra' => $this->buildExtra($extra, $contextData),

            'logged_at' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
        ];
    }

    private function formatException(Throwable $exception): string
    {
        $trace = get_class($exception).': '.$exception->getMessage()."\n";
        $trace .= 'in '.$exception->getFile().':'.$exception->getLine()."\n\n";
        $trace .= $exception->getTraceAsString();

        $previous = $exception->getPrevious();
        $depth = 0;
        while ($previous !== null && $depth < 3) {
            $trace .= "\n\nCaused by: ".get_class($previous).': '.$previous->getMessage()."\n";
            $trace .= 'in '.$previous->getFile().':'.$previous->getLine()."\n";
            $trace .= $previous->getTraceAsString();
            $previous = $previous->getPrevious();
            $depth++;
        }

        return $trace;
    }

    private function buildExtra(array $extra, array $contextData): ?string
    {
        unset(
            $extra['caller_file'], $extra['caller_line'], $extra['caller_method'],
            $extra['trace_id'], $extra['span_id'], $extra['origin'],
            $extra['app_name'], $extra['app_env'], $extra['app_url'],
            $extra['uri'], $extra['route_name'], $extra['route_action'],
            $extra['ip'], $extra['user_agent'],
            $extra['user_id'], $extra['tenant_id'], $extra['user_context'], $extra['user_label'],
            $extra['git_sha'], $extra['duration_ms'],
            $extra['job_class'], $extra['job_attempt'], $extra['queue_name'], $extra['connection_name'],
            $extra['breadcrumbs'], $extra['job_props'],
            $extra['measures'], $extra['command_name'], $extra['command_args'],
        );

        if (isset($contextData['user_context']) && is_array($contextData['user_context'])) {
            $extra['user'] = $contextData['user_context'];
            if (isset($contextData['user_label'])) {
                $extra['user_label'] = $contextData['user_label'];
            }
        } else {
            $user = auth()->user();
            if ($user instanceof HasLogContext) {
                $extra['user'] = $user->toLogContext();
                $extra['user_label'] = $user->getLogContextLabel();
            }
        }

        if (isset($contextData['command_name'])) {
            $extra['command_name'] = $contextData['command_name'];
            if (isset($contextData['command_args'])) {
                $extra['command_args'] = $contextData['command_args'];
            }
        }

        return empty($extra) ? null : json_encode($extra, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively transform context values.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function transformContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof HasLogContext) {
                $context[$key] = $value->toLogContext();
            } elseif ($value instanceof Model) {
                $context[$key] = ['_model' => get_class($value), 'id' => $value->getKey()];
            } elseif (is_object($value)) {
                $context[$key] = ['_class' => get_class($value)];
            } elseif (is_array($value)) {
                $context[$key] = $this->transformContext($value);
            }
        }

        return $context;
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
