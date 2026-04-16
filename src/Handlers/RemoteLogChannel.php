<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use Monolog\Level;
use Monolog\Logger;

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
        $handler = new RemoteHandler(level: $config['level'] ?? Level::Debug);

        $logger = new Logger('owlogs');
        $logger->pushHandler($handler);

        return $logger;
    }
}
