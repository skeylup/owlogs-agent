<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

/**
 * Back-compat surface for callers that historically reached into the
 * RemoteHandler class directly to flip the suppression flag (anti-loop guard
 * for owlogs-side jobs that themselves emit logs).
 *
 * The Monolog handler implementation now lives in {@see RemoteHandlerV2}
 * (Monolog 2) and {@see RemoteHandlerV3} (Monolog 3), selected by
 * {@see RemoteLogChannel}. Both variants read the suppression flag from this
 * class — keeping it on a single shared static so a single
 * `RemoteHandler::suppressedWhile()` call silences every active handler at
 * once, regardless of which Monolog generation is in use.
 *
 * External callers should keep using `RemoteHandler::suppressedWhile($cb)`
 * exactly as before; the API is unchanged.
 */
final class RemoteHandler
{
    /**
     * When true, every incoming record is dropped on the floor instead of
     * being buffered. Used by the internal owlogs jobs (ShipBufferedLogsJob,
     * IngestLogsJob, GenerateLogEmbeddingsJob) to break feedback loops:
     *
     *   owlogs job → Log::* / exception → owlogs channel → buffered
     *   → new ship job → same failure → loop.
     *
     * Scope is the current PHP process; queue workers serialize their job
     * execution so the flag is effectively job-scoped when toggled via
     * suppressedWhile().
     */
    public static bool $suppressed = false;

    /**
     * Execute $callback with owlogs buffering fully disabled. Any Log::* or
     * exception that would normally reach the active RemoteHandler is silently
     * dropped for the duration. Nested calls are safe — the previous state is
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
}
