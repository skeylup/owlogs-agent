<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Formatter\LineFormatter;
use Skeylup\OwlogsAgent\Processors\CallerProcessor;

/**
 * Monolog "tap" class — referenced in config/logging.php channels.
 *
 * Pushes the CallerProcessor onto every handler and sets
 * the formatter (JSON or LineFormatter with %extra%).
 */
class LogContextTap
{
    public function __invoke(Logger $logger): void
    {
        if (! config('owlogs.enabled', true)) {
            return;
        }

        $callerEnabled = config('owlogs.caller.enabled', true);
        $jsonEnabled = config('owlogs.json.enabled', true);

        foreach ($logger->getHandlers() as $handler) {
            if ($callerEnabled) {
                $handler->pushProcessor(new CallerProcessor);
            }

            if ($jsonEnabled) {
                $formatter = new JsonFormatter;
                $formatter->setJsonPrettyPrint(false);
                $handler->setFormatter($formatter);
            } else {
                $handler->setFormatter(new LineFormatter(
                    "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                    'Y-m-d H:i:s',
                    true,
                    true,
                ));
            }
        }
    }
}
