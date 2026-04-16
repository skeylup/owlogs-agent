<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class CallerProcessor implements ProcessorInterface
{
    /** @var list<string> */
    private array $ignorePaths;

    private int $maxFrames;

    public function __construct()
    {
        $config = config('owlogs.caller', []);
        $this->maxFrames = (int) ($config['max_frames'] ?? 15);
        $this->ignorePaths = $config['ignore_paths'] ?? ['/vendor/', '/packages/skeylup/owlogs-agent/src/'];
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxFrames);

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null) {
                continue;
            }

            if ($this->shouldIgnore($file)) {
                continue;
            }

            $extra = [
                'caller_file' => $this->shortenPath($file),
                'caller_line' => $frame['line'] ?? null,
            ];

            $idx = array_search($frame, $trace);
            $extra['caller_method'] = $this->resolveCallerMethod($trace, $idx + 1);

            return $record->with(extra: array_merge($record->extra, $extra));
        }

        return $record;
    }

    /**
     * Scan frames starting at $from to find the first app-level class@method.
     *
     * @param  array<int, array<string, mixed>>  $trace
     */
    private function resolveCallerMethod(array $trace, int $from): ?string
    {
        $skipClasses = [
            'Facade', 'Pipeline', 'Container', 'BoundMethod',
            'Dispatcher', 'Router', 'CallQueuedHandler', 'Kernel',
            'Application', 'RoutingServiceProvider',
        ];

        for ($i = $from, $max = count($trace); $i < $max; $i++) {
            $class = $trace[$i]['class'] ?? null;
            $function = $trace[$i]['function'] ?? null;

            if ($class === null || $function === null) {
                continue;
            }

            if (str_contains($function, '{closure')) {
                continue;
            }

            $skip = false;
            foreach ($skipClasses as $pattern) {
                if (str_contains($class, $pattern)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if (str_starts_with($class, 'Illuminate\\')
                || str_starts_with($class, 'Symfony\\')
                || str_starts_with($class, 'Monolog\\')
            ) {
                continue;
            }

            if ($function === '__invoke') {
                return null;
            }

            return class_basename($class).'@'.$function;
        }

        return null;
    }

    private function shouldIgnore(string $file): bool
    {
        foreach ($this->ignorePaths as $path) {
            if (str_contains($file, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip the base path to keep log entries short.
     */
    private function shortenPath(string $file): string
    {
        $basePath = base_path().'/';

        if (str_starts_with($file, $basePath)) {
            return substr($file, strlen($basePath));
        }

        return $file;
    }
}
