<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Middleware\AddLogContext;

class OwlogsAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/owlogs.php',
            'owlogs'
        );
    }

    public function boot(): void
    {
        if (! config('owlogs.enabled', true)) {
            return;
        }

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);
        $kernel->prependMiddleware(AddLogContext::class);

        $this->registerQueueContext();
        $this->registerCommandContext();
        $this->registerAutoInstrumentation();
        $this->registerAutoLogger();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/owlogs.php' => config_path('owlogs.php'),
            ], 'owlogs-agent-config');
        }
    }

    /**
     * Register context enrichment for queue jobs.
     */
    private function registerQueueContext(): void
    {
        if (! config('owlogs.queue.enabled', true)) {
            return;
        }

        Context::hydrated(function (Repository $context): void {
            $fields = config('owlogs.fields', []);

            if ($fields['span_id'] ?? true) {
                $context->add('span_id', (string) Str::ulid());
            }

            if ($fields['origin'] ?? true) {
                $context->add('origin', 'queue');
            }
        });

        Queue::before(function (JobProcessing $event): void {
            $queueFields = config('owlogs.queue.fields', []);

            if ($queueFields['job_class'] ?? true) {
                Context::add('job_class', $event->job->resolveName());
            }

            if ($queueFields['job_attempt'] ?? true) {
                Context::add('job_attempt', $event->job->attempts());
            }

            if ($queueFields['queue_name'] ?? true) {
                Context::add('queue_name', $event->job->getQueue());
            }

            if ($queueFields['connection_name'] ?? true) {
                Context::add('connection_name', $event->connectionName);
            }

            $this->addJobProperties($event);
        });

        // Flush the remote handler after each job so measures are captured.
        Queue::after(function (JobProcessed $event): void {
            $this->flushRemoteHandler();
        });

        // Flush policy depends on runtime:
        //   - classic PHP-FPM / CLI → register_shutdown_function (already set in
        //     RemoteHandler::write) fires at end of request / process.
        //   - Octane → the worker lives forever, shutdown hooks only fire on
        //     max-requests recycle. Register a 1s tick so the buffer drains
        //     near-realtime without per-request job overhead. The batch-size
        //     threshold (OWLOGS_BATCH_SIZE, default 50) still flushes earlier
        //     if many rows pile up in a single request.
        if (class_exists(\Laravel\Octane\Facades\Octane::class)) {
            try {
                \Laravel\Octane\Facades\Octane::tick(
                    'owlogs-remote-handler-flush',
                    fn () => $this->flushRemoteHandler(),
                )->seconds(1);
            } catch (\Throwable) {
                // tick() can throw when called outside an Octane worker
                // (e.g. artisan commands). Safe to ignore — those contexts
                // rely on shutdown / queue-after flushing instead.
            }
        }
    }

    /**
     * Force-flush the owlogs channel's RemoteHandler buffer. Silent when the
     * channel isn't wired up (e.g. non-http/queue contexts).
     */
    private function flushRemoteHandler(): void
    {
        try {
            $channel = app('log')->channel('owlogs');
            $monolog = $channel->getLogger();
            foreach ($monolog->getHandlers() as $handler) {
                if ($handler instanceof RemoteHandler) {
                    $handler->flush(true);
                }
            }
        } catch (\Throwable) {
            // Channel may not exist in all configurations.
        }
    }

    /**
     * Register context enrichment for artisan commands.
     */
    private function registerCommandContext(): void
    {
        if (! config('owlogs.commands.enabled', true)) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $ignore = ['schedule:run', 'schedule:finish', 'package:discover', 'queue:work', 'queue:listen', 'pail'];
            if (in_array($event->command, $ignore, true)) {
                return;
            }

            $fields = config('owlogs.fields', []);

            if ($fields['trace_id'] ?? true) {
                Context::addIf('trace_id', (string) Str::ulid());
            }

            if ($fields['span_id'] ?? true) {
                Context::addIf('span_id', Context::get('trace_id') ?? (string) Str::ulid());
            }

            if ($fields['origin'] ?? true) {
                Context::add('origin', 'cli');
            }

            Context::add('command_name', $event->command ?? 'unknown');

            if ($event->input) {
                $args = (string) $event->input;
                if ($args !== '') {
                    Context::add('command_args', Str::limit($args, 500));
                }
            }
        });
    }

    /**
     * Auto-instrument DB queries via QueryTracker.
     */
    private function registerAutoInstrumentation(): void
    {
        $measureConfig = config('owlogs.measure', []);

        if ($measureConfig['db_queries'] ?? false) {
            $tracker = new QueryTracker;
            $this->app->instance(QueryTracker::class, $tracker);

            DB::listen(fn ($query) => $tracker->track($query));

            // Reset between requests (Octane-safe)
            $this->app->terminating(fn () => $tracker->reset());
        }
    }

    /**
     * Register automatic lifecycle event logging.
     */
    private function registerAutoLogger(): void
    {
        (new AutoLogger)->register();
    }

    /**
     * Extract serializable public properties from the job instance.
     */
    private function addJobProperties(JobProcessing $event): void
    {
        $payload = $event->job->payload();
        $command = isset($payload['data']['command'])
            ? unserialize($payload['data']['command'])
            : null;

        if ($command === null || $command === false) {
            return;
        }

        $skipProperties = [
            'connection', 'queue', 'delay', 'afterCommit', 'middleware',
            'chained', 'chainConnection', 'chainQueue', 'chainCatchCallbacks',
            'backoff', 'maxExceptions', 'timeout', 'failOnTimeout',
            'tries', 'uniqueFor', 'uniqueId', 'deleteWhenMissingModels',
            'job', 'messageGroup', 'deduplicator', 'batchId',
        ];

        $props = [];
        $reflection = new \ReflectionClass($command);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();

            if (in_array($name, $skipProperties, true)) {
                continue;
            }

            $value = $property->getValue($command);

            if (is_scalar($value) || is_null($value) || is_array($value)) {
                $props[$name] = $value;
            }
        }

        if ($props !== []) {
            Context::add('job_props', $props);
        }
    }
}
