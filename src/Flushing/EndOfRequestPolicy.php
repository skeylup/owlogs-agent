<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Flushing;

use Skeylup\OwlogsAgent\Handlers\RemoteHandler;

/**
 * Flush policy for short-lived runtimes: PHP-FPM (Herd / Valet / classic),
 * artisan one-shot commands, and classic queue workers.
 *
 * Records accumulate in memory for the entire request / job / command and
 * are shipped exactly once when the runtime signals the boundary. The
 * handler's own register_shutdown_function stays in place as insurance
 * against fatal errors that never reach terminating callbacks.
 */
final class EndOfRequestPolicy implements FlushPolicy
{
    public function onWrite(RemoteHandler $handler): void
    {
        // No-op — the handler's hard ceiling is the only in-flight trigger.
    }

    public function onRequestBoundary(RemoteHandler $handler): void
    {
        $handler->flush(true);
    }

    public function onWorkerStopping(RemoteHandler $handler): void
    {
        $handler->flush(true);
    }

    public function onTick(RemoteHandler $handler): void
    {
        // No ticks outside Octane/Swoole.
    }
}
