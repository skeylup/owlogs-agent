<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use Monolog\Level;
use Monolog\Logger;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;

/**
 * Log channel factory for config/logging.php.
 *
 * Usage:
 *   'owlogs' => [
 *       'driver' => 'custom',
 *       'via'    => Skeylup\OwlogsAgent\Handlers\RemoteLogChannel::class,
 *       'level'  => env('LOG_LEVEL', 'debug'),
 *   ],
 */
class RemoteLogChannel
{
    public function __invoke(array $config): Logger
    {
        $policy = app(FlushPolicy::class);
        $handler = new RemoteHandler(
            level: $config['level'] ?? Level::Debug,
            policy: $policy,
        );

        $logger = new Logger('owlogs');
        $logger->pushHandler($handler);

        return $logger;
    }
}
