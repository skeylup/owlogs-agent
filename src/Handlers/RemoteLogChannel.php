<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use Monolog\Level;
use Monolog\Logger;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

/**
 * Log channel factory for config/logging.php.
 *
 * Picks the right Monolog-generation handler at boot:
 *  - Monolog 3 (Laravel 10+, plus the 3.x backport in late L9) → {@see RemoteHandlerV3}
 *  - Monolog 2 (Laravel 8.65 → 9.49) → {@see RemoteHandlerV2}
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
        $store = app(LogBufferStore::class);
        $monologApi = (int) (defined(Logger::class.'::API') ? Logger::API : 2);

        if ($monologApi >= 3) {
            $handler = new RemoteHandlerV3(
                level: $config['level'] ?? Level::Debug,
                policy: $policy,
                store: $store,
            );
        } else {
            $handler = new RemoteHandlerV2(
                level: $config['level'] ?? Logger::DEBUG,
                policy: $policy,
                store: $store,
            );
        }

        $logger = new Logger('owlogs');
        $logger->pushHandler($handler);

        return $logger;
    }
}
