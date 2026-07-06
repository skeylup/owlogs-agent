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
    /**
     * RFC 5424 numeric severities shared by Monolog 2 and 3 — lets the trait
     * translate the configured `dedup.cap_min_level` name without depending
     * on either Monolog generation's level API.
     */
    private const MONOLOG_LEVELS = [
        'debug' => 100,
        'info' => 200,
        'notice' => 250,
        'warning' => 300,
        'error' => 400,
        'critical' => 500,
        'alert' => 550,
        'emergency' => 600,
    ];

    private const DEDUP_RATE_KEY = 'owlogs:dedup:rate:';

    private const DEDUP_SAMPLE_KEY = 'owlogs:dedup:sample:';

    private const DEDUP_SUPPRESSED_KEY = 'owlogs:dedup:suppressed:';

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
     * Window-scoped collapse index: fingerprint → offset of the buffered row
     * identical occurrences merge into. Reset on every flush() so entries can
     * never point into a drained buffer — and so no dedup state leaks across
     * flush windows (or across requests under Octane).
     *
     * @var array<string, int>
     */
    private array $dedupIndex = [];

    /**
     * Window-scoped tally of rows dropped by the per-fingerprint rate cap.
     * Pushed to the shared Cache counter at flush time and shipped later as
     * `suppressed_count` on the next row of the same fingerprint, so the
     * server's occurrence statistics stay exact.
     *
     * @var array<string, int>
     */
    private array $suppressedInWindow = [];

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
     * Error-storm dedup happens here, BEFORE the row costs buffer memory,
     * store I/O or wire bytes:
     *
     *  - Window collapse: identical rows (same level + exception class +
     *    caller file:line + message + user) within the current flush window
     *    merge into a single buffered row whose `count` / `first_at` /
     *    `last_at` fields carry the exact tally. State is window-scoped and
     *    reset on flush — no cross-request leakage under Octane.
     *  - Per-fingerprint rate cap (levels >= `dedup.cap_min_level` only):
     *    once a fingerprint has shipped `dedup.max_per_minute` rows within a
     *    minute (Cache-metered, so the cap holds across FPM processes and
     *    Octane workers), further occurrences are dropped and only one
     *    sampled row per `dedup.sample_interval_s` goes out, carrying the
     *    accumulated `suppressed_count`.
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
        // window): drop low-severity records — buffering them would just
        // grow the backlog for shipments we know will shed them anyway —
        // but KEEP buffering rows at/above `circuit.retain_min_level`
        // (default error). The store's FIFO cap bounds the retained
        // backlog; drops are tallied and reported in one synthetic
        // diagnostic row once the circuit closes.
        if (IngestCircuit::isTripped() && $levelValue < $this->circuitRetainMinLevel()) {
            IngestCircuit::recordDropped('low_severity');

            return;
        }

        $fingerprint = null;

        if ($this->dedupEnabled()) {
            $fingerprint = $this->fingerprint($levelValue, $message, $context, $extra);

            // Identical row already buffered this window: collapse into it.
            if (isset($this->dedupIndex[$fingerprint])) {
                $this->collapseInto($this->dedupIndex[$fingerprint], $datetime);
                $this->policy->onWrite($this);

                return;
            }

            // Fingerprint already suppressed this window: tally and drop.
            if (isset($this->suppressedInWindow[$fingerprint])) {
                $this->suppressedInWindow[$fingerprint]++;
                $this->policy->onWrite($this);

                return;
            }

            if ($this->levelIsRateCapped($levelValue)) {
                $shippedThisMinute = $this->meterShippedRow($fingerprint);

                if ($shippedThisMinute > $this->maxRowsPerMinute() && ! $this->acquireSampleSlot($fingerprint)) {
                    // Over the per-minute cap and a sampled row already went
                    // out within the sample interval: suppress. The tally is
                    // persisted at flush time and rides out on the next row
                    // of this fingerprint as `suppressed_count`.
                    $this->suppressedInWindow[$fingerprint] = 1;
                    $this->registerShutdownFlush();
                    $this->policy->onWrite($this);

                    return;
                }
            }
        }

        $row = $this->buildRow($channel, $levelValue, $levelName, $message, $context, $extra, $datetime);

        if ($fingerprint !== null && $this->levelIsRateCapped($levelValue)) {
            $pendingSuppressed = $this->claimSuppressedCount($fingerprint);
            if ($pendingSuppressed > 0) {
                $row['suppressed_count'] = $pendingSuppressed;
            }
        }

        $encoded = json_encode($row, JSON_UNESCAPED_UNICODE);
        $this->bufferBytes += $encoded !== false ? strlen($encoded) : 0;
        $this->buffer[] = $row;

        if ($fingerprint !== null) {
            $this->dedupIndex[$fingerprint] = count($this->buffer) - 1;
        }

        if ($this->firstBufferedAt === null) {
            $this->firstBufferedAt = microtime(true);
        }

        $this->registerShutdownFlush();

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

    private function dedupEnabled(): bool
    {
        return (bool) config('owlogs.dedup.enabled', true);
    }

    /**
     * Identity of a row for collapse / rate-cap purposes: level + exception
     * class + caller file:line + message + user. The user id is part of the
     * fingerprint on purpose — collapsing across actors would lose the
     * per-user rows "who was affected" queries rely on during storms.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $extra
     */
    private function fingerprint(int $levelValue, string $message, array $context, array $extra): string
    {
        $exceptionClass = isset($context['exception']) && $context['exception'] instanceof Throwable
            ? get_class($context['exception'])
            : '';

        $userId = ContextShim::getHidden('user_id');

        return md5(
            $levelValue.'|'
            .$exceptionClass.'|'
            .(string) ($extra['caller_file'] ?? '').':'.(string) ($extra['caller_line'] ?? '').'|'
            .$message.'|'
            .(is_scalar($userId) ? (string) $userId : ''),
        );
    }

    /**
     * Merge an identical occurrence into the row buffered earlier in this
     * flush window: bump `count`, stamp `first_at` / `last_at` so the exact
     * spread of the collapsed occurrences survives the merge.
     */
    private function collapseInto(int $index, DateTimeInterface $datetime): void
    {
        $this->buffer[$index]['count'] = (int) ($this->buffer[$index]['count'] ?? 1) + 1;

        if (! isset($this->buffer[$index]['first_at'])) {
            $this->buffer[$index]['first_at'] = $this->buffer[$index]['logged_at'];
        }

        $this->buffer[$index]['last_at'] = $datetime
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.v');
    }

    private function levelIsRateCapped(int $levelValue): bool
    {
        $name = strtolower((string) config('owlogs.dedup.cap_min_level', 'warning'));

        return $levelValue >= (self::MONOLOG_LEVELS[$name] ?? self::MONOLOG_LEVELS['warning']);
    }

    /**
     * Minimum severity kept while the ingest circuit is tripped. Rows at or
     * above this level keep flowing to the cross-process store during the
     * cooldown (the server accepts them on its quota grace budget); lower
     * rows are dropped and tallied.
     */
    private function circuitRetainMinLevel(): int
    {
        $name = strtolower((string) config('owlogs.transport.circuit.retain_min_level', 'error'));

        return self::MONOLOG_LEVELS[$name] ?? self::MONOLOG_LEVELS['error'];
    }

    /**
     * Persist the retained-severity slice of a flush that happened while the
     * circuit was tripped; tally the dropped remainder for the diagnostic row.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function storeRetainedSlice(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $minLevel = $this->circuitRetainMinLevel();
        $retained = [];
        $droppedLow = 0;

        foreach ($rows as $row) {
            if ((int) ($row['level'] ?? 0) >= $minLevel) {
                $retained[] = $row;
            } else {
                // Collapsed rows represent `count` occurrences each.
                $droppedLow += max(1, (int) ($row['count'] ?? 1));
            }
        }

        if ($droppedLow > 0) {
            IngestCircuit::recordDropped('low_severity', $droppedLow);
        }

        if ($retained === []) {
            return;
        }

        try {
            $this->store->append($retained);
        } catch (Throwable) {
            // Store momentarily unavailable — same silent contract as the
            // regular flush path.
        }
    }

    private function maxRowsPerMinute(): int
    {
        return max(1, (int) config('owlogs.dedup.max_per_minute', 60));
    }

    /**
     * Count one shipped row for this fingerprint in the current minute and
     * return the running total. Cache-backed so the cap holds across FPM
     * processes and Octane workers alike; on Cache failure the cap silently
     * disengages — shipping beats losing data.
     */
    private function meterShippedRow(string $fingerprint): int
    {
        try {
            $key = self::DEDUP_RATE_KEY.$fingerprint;
            Cache::add($key, 0, now()->addSeconds(60));

            return (int) Cache::increment($key);
        } catch (Throwable) {
            return 1;
        }
    }

    /**
     * True when this over-cap occurrence may go out as the one sampled row
     * of the current sample interval.
     */
    private function acquireSampleSlot(string $fingerprint): bool
    {
        $intervalS = max(1, (int) config('owlogs.dedup.sample_interval_s', 30));

        try {
            return Cache::add(self::DEDUP_SAMPLE_KEY.$fingerprint, 1, now()->addSeconds($intervalS));
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Claim the accumulated suppressed tally for a fingerprint so the row
     * being buffered can carry it as `suppressed_count`. Decrements instead
     * of deleting so concurrent increments from other processes are never
     * lost.
     */
    private function claimSuppressedCount(string $fingerprint): int
    {
        $key = self::DEDUP_SUPPRESSED_KEY.$fingerprint;

        try {
            $pending = (int) Cache::get($key, 0);
            if ($pending > 0) {
                Cache::decrement($key, $pending);
            }

            return $pending;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Push the window's suppressed tallies to the shared Cache counters at
     * flush time (one increment per fingerprint per window — never one per
     * suppressed row). Generous TTL so a storm tail is still claimed by the
     * next row of the same fingerprint minutes later.
     *
     * @param  array<string, int>  $tallies
     */
    private function persistSuppressedTallies(array $tallies): void
    {
        foreach ($tallies as $fingerprint => $count) {
            try {
                $key = self::DEDUP_SUPPRESSED_KEY.$fingerprint;
                Cache::add($key, 0, now()->addSeconds(900));
                Cache::increment($key, $count);
            } catch (Throwable) {
                // Best-effort — a lost tally only undercounts suppressed rows.
            }
        }
    }

    private function registerShutdownFlush(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function(function (): void {
            $this->flush(true);
        });
    }

    /**
     * Flush: drain the RAM buffer into the cross-process LogBufferStore
     * and debounce-dispatch a ShipBufferedLogsJob. Attaches the process
     * memory peak to the last row of the batch (measures are captured
     * per-row at log time in buildRow(), not here). Also resets the
     * window-scoped dedup state and persists the window's suppressed
     * tallies.
     *
     * @param  bool  $force  reserved for future use; flush is always unconditional now
     */
    public function flush(bool $force = false): void
    {
        if ($this->buffer === [] && $this->suppressedInWindow === []) {
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
            $this->dedupIndex = [];

            $suppressed = $this->suppressedInWindow;
            $this->suppressedInWindow = [];

            if (IngestCircuit::isTripped()) {
                // Persist ONLY the retained-severity slice to the store so
                // error/critical rows survive the cooldown (FIFO-capped);
                // lower levels buffered before the trip are dropped and
                // tallied for the post-outage diagnostic row. No ship job
                // is dispatched while the circuit is open.
                $this->persistSuppressedTallies($suppressed);
                $this->storeRetainedSlice($rows);

                return;
            }

            $this->persistSuppressedTallies($suppressed);

            if ($rows === []) {
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

            // Number of identical occurrences this row represents. Starts at
            // 1 and grows when the window collapse merges duplicates in;
            // servers unaware of the field treat every row as one occurrence.
            'count' => 1,

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
