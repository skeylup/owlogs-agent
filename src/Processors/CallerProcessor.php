<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Processors;

use Monolog\LogRecord;

/**
 * Resolve the caller (file:line + class@method) of a log record and stash it
 * under `extra.caller_file` / `caller_line` / `caller_method`.
 *
 * Intentionally does not `implements ProcessorInterface` — the interface
 * exists in both Monolog 2 and Monolog 3 but with incompatible signatures:
 *
 *  - Monolog 2: `__invoke(array $record): array`
 *  - Monolog 3: `__invoke(LogRecord $record): LogRecord`
 *
 * Monolog accepts any callable as a processor (not just instances of
 * ProcessorInterface), so we skip the formal interface and let `__invoke()`
 * accept either shape, dispatching on `instanceof LogRecord` at runtime.
 */
class CallerProcessor
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

    /**
     * @param  LogRecord|array<string, mixed>  $record
     * @return LogRecord|array<string, mixed>
     *
     * Untyped on purpose — PHP resolves union types at call time and the
     * `LogRecord` class does not exist on Monolog 2, which would error out
     * before we ever reach the `instanceof` branch.
     */
    public function __invoke(mixed $record): mixed
    {
        $extra = $this->resolveExtra();
        if ($extra === []) {
            return $record;
        }

        // Monolog 3 → immutable LogRecord, must rebuild via with()
        if ($record instanceof LogRecord) {
            return $record->with(extra: array_merge($record->extra, $extra));
        }

        // Monolog 2 → record is a plain array, mutate in place
        $record['extra'] = array_merge($record['extra'] ?? [], $extra);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveExtra(): array
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $this->maxFrames);

        foreach ($trace as $idx => $frame) {
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
            $extra['caller_method'] = $this->resolveCallerMethod($trace, $idx + 1);

            return $extra;
        }

        return [];
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
