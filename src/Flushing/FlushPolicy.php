<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Flushing;

use Skeylup\OwlogsAgent\Handlers\RemoteHandler;

/**
 * Decides *when* the buffered log records held by RemoteHandler are shipped.
 *
 * The handler itself only buffers + enforces a memory hard ceiling; every
 * other decision (per-request, time-windowed, count-based) is delegated
 * here so runtime concerns (FPM vs Octane vs Swoole tick) stay out of the
 * Monolog write path.
 */
interface FlushPolicy
{
    /**
     * Called after every record is buffered.
     */
    public function onWrite(RemoteHandler $handler): void;

    /**
     * Called at a request / job / command boundary (Laravel terminating(),
     * Octane RequestTerminated / TaskTerminated, Queue::after,
     * CommandFinished).
     */
    public function onRequestBoundary(RemoteHandler $handler): void;

    /**
     * Called when a long-lived worker is stopping. Always force-flush.
     */
    public function onWorkerStopping(RemoteHandler $handler): void;

    /**
     * Called on a timer tick when the runtime supports it (Octane::tick on
     * Swoole). No-op for runtimes without ticks.
     */
    public function onTick(RemoteHandler $handler): void;
}
