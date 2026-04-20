<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Flushing;

use Closure;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;

/**
 * Flush policy for long-lived Octane workers (swoole / roadrunner /
 * frankenphp). Records may accumulate across multiple requests served by
 * the same worker; the buffer is shipped when either condition hits:
 *
 *   - count(buffer) >= transport.octane.batch_count (default 20)
 *   - ms since the first buffered record >= transport.octane.window_ms (default 2000)
 *
 * Config is read lazily so that Octane's per-request ConfigRepository reset
 * is respected. A Closure $now is injectable for deterministic tests.
 *
 * On roadrunner / frankenphp there is no equivalent to Octane::tick, so the
 * window is only checked on onWrite and onRequestBoundary. Buffered records
 * on an otherwise idle worker will therefore wait until the next request
 * or WorkerStopping — acceptable because worker idle time is bounded by
 * max_request_lifetime / max_jobs in practice.
 */
final class OctaneWindowPolicy implements FlushPolicy
{
    private ?float $windowStartedAt = null;

    /** @var Closure(): float */
    private Closure $now;

    public function __construct(?Closure $now = null)
    {
        $this->now = $now ?? static fn (): float => microtime(true);
    }

    public function onWrite(RemoteHandler $handler): void
    {
        if ($handler->bufferCount() === 0) {
            return;
        }

        if ($this->windowStartedAt === null) {
            $this->windowStartedAt = ($this->now)();
        }

        $this->flushIfDue($handler);
    }

    public function onRequestBoundary(RemoteHandler $handler): void
    {
        $this->flushIfDue($handler);
    }

    public function onWorkerStopping(RemoteHandler $handler): void
    {
        $handler->flush(true);
        $this->windowStartedAt = null;
    }

    public function onTick(RemoteHandler $handler): void
    {
        $this->flushIfDue($handler);
    }

    private function flushIfDue(RemoteHandler $handler): void
    {
        if ($handler->bufferCount() === 0) {
            $this->windowStartedAt = null;

            return;
        }

        $batchCount = (int) config('owlogs.transport.octane.batch_count', 20);
        $windowMs = (int) config('owlogs.transport.octane.window_ms', 2000);

        $countReached = $handler->bufferCount() >= $batchCount;

        $windowElapsed = false;
        if ($this->windowStartedAt !== null) {
            $elapsedMs = (($this->now)() - $this->windowStartedAt) * 1000;
            $windowElapsed = $elapsedMs >= $windowMs;
        }

        if (! $countReached && ! $windowElapsed) {
            return;
        }

        $handler->flush(true);
        $this->windowStartedAt = null;
    }
}
