<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers\Concerns;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Skeylup\OwlogsAgent\Compat\ContextShim;
use Skeylup\OwlogsAgent\Contracts\HasLogContext;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\IngestCircuit;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Throwable;

/**
 * Shared buffering + flush + ship logic for the Monolog 2 and Monolog 3
 * RemoteHandler variants. Pulled into a trait so the Monolog-version-specific
 * `write()` signatures (`write(array)` on Monolog 2, `write(LogRecord)` on
 * Monolog 3) can each normalise their record into a generic component bag
 * before delegating to {@see self::bufferOne()}.
 *
 * Reads the global suppression flag from {@see RemoteHandler::$suppressed}
 * (intentionally a single shared static across all handler instances so
 * `RemoteHandler::suppressedWhile()` toggles every handler at once).
 */
trait BuffersAndShips
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

    /**
     * Buffer a single normalised record. Called from the Monolog-version
     * specific `write()` override after it has decomposed the native record
     * into its constituent parts.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    protected function bufferOne(
        string $channel,
        int $levelValue,
        string $levelName,
        string $message,
        array $context,
        array $extra,
        DateTimeInterface $datetime,
    ): void {
        if (RemoteHandler::$suppressed) {
            return;
        }

        // Circuit tripped (server returned 403/429 within the cooldown
        // window): drop the record outright. Buffering it would just
        // grow the client's Redis/file backlog for shipments we know
        // will be rejected anyway.
        if (IngestCircuit::isTripped()) {
            return;
        }

        $row = $this->buildRow($channel, $levelValue, $levelName, $message, $context, $extra, $datetime);
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
     * and debounce-dispatch a ShipBufferedLogsJob. Attaches the process
     * memory peak to the last row of the batch (measures are captured
     * per-row at log time in buildRow(), not here).
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

            if (IngestCircuit::isTripped()) {
                return;
            }

            if (config('owlogs.measure.memory', true)) {
                $lastIdx = count($rows) - 1;
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

    /**
     * Dispatch a ShipBufferedLogsJob at most once per debounce window.
     */
    private function scheduleShip(): void
    {
        if (IngestCircuit::isTripped()) {
            return;
        }

        $debounceMs = (int) config('owlogs.transport.ship.debounce_ms', 10000);
        $debounceSec = max(1, (int) ceil($debounceMs / 1000));
        $markerTtl = $debounceSec + 60;

        try {
            $acquired = Cache::add(
                ShipBufferedLogsJob::PENDING_CACHE_KEY,
                1,
                now()->addSeconds($markerTtl),
            );
        } catch (Throwable) {
            $acquired = true;
        }

        if (! $acquired) {
            return;
        }

        try {
            ShipBufferedLogsJob::dispatch()->delay(now()->addSeconds($debounceSec));
        } catch (Throwable) {
            // Silent: queue backend may be unavailable.
        }
    }

    /**
     * Compose the final DB-ready row from per-record components + ContextShim
     * snapshot. Identical output across Monolog 2/3 — only the path that
     * supplies the components differs.
     *
     * @param  array<string, mixed>  $userContext
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function buildRow(
        string $channel,
        int $levelValue,
        string $levelName,
        string $message,
        array $userContext,
        array $extra,
        DateTimeInterface $datetime,
    ): array {
        $contextData = ContextShim::allHidden();

        $stacktrace = null;
        if (isset($userContext['exception']) && $userContext['exception'] instanceof Throwable) {
            $stacktrace = $this->formatException($userContext['exception']);
            unset($userContext['exception']);
        }

        $userContext = $this->transformContext($userContext);

        return [
            'trace_id' => $contextData['trace_id'] ?? null,
            'span_id' => $contextData['span_id'] ?? null,
            'parent_span_id' => $contextData['parent_span_id'] ?? null,
            'origin' => $contextData['origin'] ?? null,

            'level_name' => $levelName,
            'level' => $levelValue,
            'channel' => $channel,
            'message' => $message,
            'stacktrace' => $stacktrace,

            'caller_file' => $extra['caller_file'] ?? null,
            'caller_line' => $extra['caller_line'] ?? null,
            'caller_method' => $extra['caller_method'] ?? null,

            'uri' => $this->truncate($contextData['uri'] ?? null, 2048),
            'http_method' => $this->truncate($contextData['http_method'] ?? null, 8),
            'route_name' => $contextData['route_name'] ?? null,
            'route_action' => $this->truncate($contextData['route_action'] ?? null, 512),
            'ip' => $contextData['ip'] ?? null,
            'user_agent' => $this->truncate($contextData['user_agent'] ?? null, 512),
            'request_input' => $contextData['request_input'] ?? null,

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
            // `breadcrumbs` field retired in favour of standalone auto-logs
            // tagged with the shared trace_id. Field kept on the row schema
            // for backwards-compat with the existing DB column.
            'breadcrumbs' => null,
            'job_props' => isset($contextData['job_props']) ? json_encode($contextData['job_props'], JSON_UNESCAPED_UNICODE) : null,
            'measures' => isset($contextData['measures']) ? json_encode($contextData['measures'], JSON_UNESCAPED_UNICODE) : null,
            'memory_peak_mb' => null,
            'extra' => $this->buildExtra($extra, $contextData),

            'logged_at' => $datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v'),
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

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $contextData
     */
    private function buildExtra(array $extra, array $contextData): ?string
    {
        unset(
            $extra['caller_file'], $extra['caller_line'], $extra['caller_method'],
            $extra['trace_id'], $extra['span_id'], $extra['origin'],
            $extra['app_name'], $extra['app_env'], $extra['app_url'],
            $extra['uri'], $extra['http_method'], $extra['route_name'], $extra['route_action'],
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

        if (isset($contextData['livewire_calls']) && is_array($contextData['livewire_calls']) && $contextData['livewire_calls'] !== []) {
            $extra['livewire'] = ['calls' => $contextData['livewire_calls']];
        }

        return empty($extra) ? null : json_encode($extra, JSON_UNESCAPED_UNICODE);
    }

    /**
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
