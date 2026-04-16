<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Support\Facades\Context;

/**
 * Lightweight performance measurement tool.
 *
 * Records spans (start/stop durations) during a request or job.
 * Spans are stored in Context and persisted to the `measures` JSON column.
 *
 * Usage:
 *   Measure::start('generate_pdf');
 *   // ... work ...
 *   Measure::stop('generate_pdf');
 *
 *   // Or with a closure:
 *   $result = Measure::track('call_api', fn () => Http::get('...'));
 *
 *   // Simple checkpoint (instant, no duration):
 *   Measure::checkpoint('cache_hit', ['key' => 'user:42']);
 */
class Measure
{
    /**
     * Active spans awaiting stop().
     *
     * @var array<string, array{start: float, meta: array<string, mixed>}>
     */
    private static array $pending = [];

    /**
     * Start a named span.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function start(string $label, array $meta = []): void
    {
        static::$pending[$label] = [
            'start' => hrtime(true),
            'meta' => $meta,
        ];
    }

    /**
     * Stop a named span and record its duration.
     *
     * @param  array<string, mixed>  $extraMeta  Merged with start meta.
     */
    public static function stop(string $label, array $extraMeta = []): ?float
    {
        if (! isset(static::$pending[$label])) {
            return null;
        }

        $span = static::$pending[$label];
        unset(static::$pending[$label]);

        $durationMs = (hrtime(true) - $span['start']) / 1_000_000;

        $entry = [
            'label' => $label,
            'duration_ms' => round($durationMs, 2),
            'meta' => array_merge($span['meta'], $extraMeta),
        ];

        Context::push('measures', $entry);

        return $durationMs;
    }

    /**
     * Measure a closure and return its result.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  array<string, mixed>  $meta
     * @return T
     */
    public static function track(string $label, callable $callback, array $meta = []): mixed
    {
        static::start($label, $meta);

        try {
            $result = $callback();
        } finally {
            static::stop($label);
        }

        return $result;
    }

    /**
     * Record an instant checkpoint (no duration, just a timestamp marker).
     *
     * @param  array<string, mixed>  $meta
     */
    public static function checkpoint(string $label, array $meta = []): void
    {
        Context::push('measures', [
            'label' => $label,
            'duration_ms' => 0,
            'meta' => $meta,
        ]);
    }

    /**
     * Get all recorded spans.
     *
     * @return list<array{label: string, duration_ms: float, meta: array<string, mixed>}>
     */
    public static function all(): array
    {
        return Context::get('measures') ?? [];
    }

    /**
     * Get total duration of all spans.
     */
    public static function totalMs(): float
    {
        return array_sum(array_column(static::all(), 'duration_ms'));
    }

    /**
     * Clear all spans and pending measurements.
     */
    public static function clear(): void
    {
        static::$pending = [];
        Context::forget('measures');
    }
}
