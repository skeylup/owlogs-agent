<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Contracts\HasLogContext;
use Skeylup\OwlogsAgent\Jobs\SendLogsJob;
use Throwable;

/**
 * Monolog handler that buffers log records and ships them to the Owlogs server
 * via a queue job. Buffers in memory, flushes on batch size / shutdown / queue after.
 */
class RemoteHandler extends AbstractProcessingHandler
{
    private int $batchSize;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    public function __construct(
        Level|int|string $level = Level::Debug,
        bool $bubble = true,
    ) {
        parent::__construct($level, $bubble);

        $this->batchSize = (int) config('owlogs.transport.batch_size', 50);
    }

    protected function write(LogRecord $record): void
    {
        $row = $this->buildRow($record);
        $this->buffer[] = $row;

        if (! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function([$this, 'flush']);
        }

        if (count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Flush buffered rows by dispatching a SendLogsJob.
     *
     * Attaches the current measures snapshot and memory peak to the
     * last row of the batch — this gives the most complete picture.
     */
    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        $rows = $this->buffer;
        $this->buffer = [];

        // Attach measures + memory to the last entry of the batch
        $lastIdx = count($rows) - 1;
        $measures = Context::get('measures');
        if ($measures !== null) {
            $rows[$lastIdx]['measures'] = json_encode($measures, JSON_UNESCAPED_UNICODE);
        }
        if (config('owlogs.measure.memory', true)) {
            $rows[$lastIdx]['memory_peak_mb'] = (int) round(memory_get_peak_usage(true) / 1024 / 1024);
        }

        try {
            SendLogsJob::dispatch($rows);
        } catch (Throwable) {
            // Silent failure: queue backend may be unavailable.
            // The local file channel (in parallel) still captures entries.
        }
    }

    public function close(): void
    {
        $this->flush();
        parent::close();
    }

    /**
     * Build a database-ready row from a Monolog record.
     *
     * @return array<string, mixed>
     */
    private function buildRow(LogRecord $record): array
    {
        $contextData = Context::all();
        $extra = $record->extra;
        $userContext = $record->context;

        // Stacktrace from exception
        $stacktrace = null;
        if (isset($userContext['exception']) && $userContext['exception'] instanceof Throwable) {
            $stacktrace = $this->formatException($userContext['exception']);
            unset($userContext['exception']);
        }

        // Transform models implementing HasLogContext into their safe representation
        $userContext = $this->transformContext($userContext);

        return [
            'trace_id' => $contextData['trace_id'] ?? null,
            'span_id' => $contextData['span_id'] ?? null,
            'origin' => $contextData['origin'] ?? null,

            'level_name' => $record->level->getName(),
            'level' => $record->level->value,
            'channel' => $record->channel,
            'message' => $record->message,
            'stacktrace' => $stacktrace,

            'caller_file' => $extra['caller_file'] ?? null,
            'caller_line' => $extra['caller_line'] ?? null,
            'caller_method' => $extra['caller_method'] ?? null,

            'uri' => $this->truncate($contextData['uri'] ?? null, 2048),
            'route_name' => $contextData['route_name'] ?? null,
            'route_action' => $this->truncate($contextData['route_action'] ?? null, 512),
            'ip' => $contextData['ip'] ?? null,
            'user_agent' => $this->truncate($contextData['user_agent'] ?? null, 512),
            'request_input' => $contextData['request_input'] ?? null,

            'user_id' => $contextData['user_id'] ?? auth()->id(),
            'tenant_id' => $contextData['tenant_id'] ?? null,

            'app_name' => $contextData['app_name'] ?? null,
            'app_env' => $contextData['app_env'] ?? null,
            'app_url' => $contextData['app_url'] ?? null,
            'git_sha' => $contextData['git_sha'] ?? null,

            'job_class' => $contextData['job_class'] ?? null,
            'job_attempt' => $contextData['job_attempt'] ?? null,
            'queue_name' => $contextData['queue_name'] ?? null,
            'connection_name' => $contextData['connection_name'] ?? null,

            'duration_ms' => $contextData['duration_ms'] ?? null,

            'context' => ! empty($userContext) ? json_encode($userContext, JSON_UNESCAPED_UNICODE) : null,
            'breadcrumbs' => isset($contextData['breadcrumbs']) ? json_encode($contextData['breadcrumbs'], JSON_UNESCAPED_UNICODE) : null,
            'job_props' => isset($contextData['job_props']) ? json_encode($contextData['job_props'], JSON_UNESCAPED_UNICODE) : null,
            'measures' => null,
            'memory_peak_mb' => null,
            'extra' => $this->buildExtra($extra, $contextData),

            'logged_at' => $record->datetime->format('Y-m-d H:i:s.v'),
        ];
    }

    private function formatException(Throwable $exception): string
    {
        $trace = get_class($exception).': '.$exception->getMessage()."\n";
        $trace .= 'in '.$exception->getFile().':'.$exception->getLine()."\n\n";
        $trace .= $exception->getTraceAsString();

        $previous = $exception->getPrevious();
        $depth = 0;
        while ($previous !== null && $depth < 3) {
            $trace .= "\n\nCaused by: ".get_class($previous).': '.$previous->getMessage()."\n";
            $trace .= 'in '.$previous->getFile().':'.$previous->getLine()."\n";
            $trace .= $previous->getTraceAsString();
            $previous = $previous->getPrevious();
            $depth++;
        }

        return $trace;
    }

    private function buildExtra(array $extra, array $contextData): ?string
    {
        unset(
            $extra['caller_file'], $extra['caller_line'], $extra['caller_method'],
            $extra['trace_id'], $extra['span_id'], $extra['origin'],
            $extra['app_name'], $extra['app_env'], $extra['app_url'],
            $extra['uri'], $extra['route_name'], $extra['route_action'],
            $extra['ip'], $extra['user_agent'],
            $extra['user_id'], $extra['tenant_id'], $extra['user_context'], $extra['user_label'],
            $extra['git_sha'], $extra['duration_ms'],
            $extra['job_class'], $extra['job_attempt'], $extra['queue_name'], $extra['connection_name'],
            $extra['breadcrumbs'], $extra['job_props'],
            $extra['measures'], $extra['command_name'], $extra['command_args'],
        );

        if (isset($contextData['user_context']) && is_array($contextData['user_context'])) {
            $extra['user'] = $contextData['user_context'];
            if (isset($contextData['user_label'])) {
                $extra['user_label'] = $contextData['user_label'];
            }
        } else {
            $user = auth()->user();
            if ($user instanceof HasLogContext) {
                $extra['user'] = $user->toLogContext();
                $extra['user_label'] = $user->getLogContextLabel();
            }
        }

        if (isset($contextData['command_name'])) {
            $extra['command_name'] = $contextData['command_name'];
            if (isset($contextData['command_args'])) {
                $extra['command_args'] = $contextData['command_args'];
            }
        }

        return empty($extra) ? null : json_encode($extra, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Recursively transform context values.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function transformContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof HasLogContext) {
                $context[$key] = $value->toLogContext();
            } elseif ($value instanceof Model) {
                $context[$key] = ['_model' => get_class($value), 'id' => $value->getKey()];
            } elseif (is_object($value)) {
                $context[$key] = ['_class' => get_class($value)];
            } elseif (is_array($value)) {
                $context[$key] = $this->transformContext($value);
            }
        }

        return $context;
    }

    private function truncate(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
