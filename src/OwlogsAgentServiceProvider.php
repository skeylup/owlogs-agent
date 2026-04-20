<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Octane\Events\RequestTerminated;
use Laravel\Octane\Events\TaskTerminated;
use Laravel\Octane\Events\WorkerStopping;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Handlers\RemoteLogChannel;
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

        $this->registerLogChannel();

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
     * Auto-define the `owlogs` log channel and (optionally) append it to the
     * `stack` channel so consumers don't have to edit config/logging.php or
     * LOG_STACK for logs to flow.
     *
     * A pre-existing `owlogs` channel definition is never overwritten, and
     * the stack injection preserves any custom channel list the user had
     * already built (e.g. array_filter([...slack_dedup...])).
     */
    private function registerLogChannel(): void
    {
        if (! config()->has('logging.channels.owlogs')) {
            config(['logging.channels.owlogs' => [
                'driver' => 'custom',
                'via' => RemoteLogChannel::class,
                'level' => env('LOG_LEVEL', 'debug'),
                'tap' => [LogContextTap::class],
            ]]);
        }

        if (! config('owlogs.auto_register_stack', true)) {
            return;
        }

        // Only inject into `stack` if the user actually has one; avoid
        // materialising a half-baked stack definition otherwise.
        if (! config()->has('logging.channels.stack.channels')) {
            return;
        }

        $stack = (array) config('logging.channels.stack.channels', []);

        if (! in_array('owlogs', $stack, true)) {
            config(['logging.channels.stack.channels' => [...$stack, 'owlogs']]);
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

            // Defensive fill: if the dispatcher didn't set these (e.g. job
            // dispatched from a bare CLI, tinker, or an external process),
            // populate them from config so log rows are never missing the
            // app identity. addIf() never overwrites an existing value.
            if (($fields['app_name'] ?? true) && ! $context->has('app_name')) {
                $context->add('app_name', (string) config('app.name'));
            }
            if (($fields['app_env'] ?? true) && ! $context->has('app_env')) {
                $context->add('app_env', (string) config('app.env'));
            }
            if (($fields['app_url'] ?? true) && ! $context->has('app_url')) {
                $context->add('app_url', (string) config('app.url'));
            }
            if (($fields['git_sha'] ?? true) && ! $context->has('git_sha')) {
                $sha = AddLogContext::resolveGitSha();
                if ($sha !== null) {
                    $context->add('git_sha', $sha);
                }
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

        // Flush policy by runtime:
        //   - PHP-FPM / CLI → register_shutdown_function (set in RemoteHandler::write).
        //   - Octane (Swoole / RoadRunner / FrankenPHP) → workers are long-lived so
        //     shutdown hooks only run on recycle. Octane::tick() is Swoole-only, so
        //     we listen to request/task/worker lifecycle events instead — all three
        //     runtimes dispatch them. flush() is debounced via min_flush_interval_ms
        //     and short-circuits on empty buffer, so per-request listeners are cheap.
        $this->registerOctaneFlushListeners();
    }

    private function registerOctaneFlushListeners(): void
    {
        $events = [
            RequestTerminated::class,
            TaskTerminated::class,
            WorkerStopping::class,
        ];

        foreach ($events as $event) {
            if (class_exists($event)) {
                Event::listen($event, fn () => $this->flushRemoteHandler());
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

        $ignore = ['schedule:run', 'schedule:finish', 'package:discover', 'queue:work', 'queue:listen', 'pail'];
        $startTime = null;

        Event::listen(CommandStarting::class, function (CommandStarting $event) use ($ignore, &$startTime): void {
            if (in_array($event->command, $ignore, true)) {
                return;
            }

            $fields = config('owlogs.fields', []);
            $startTime = hrtime(true);

            if ($fields['trace_id'] ?? true) {
                Context::addIf('trace_id', (string) Str::ulid());
            }

            if ($fields['span_id'] ?? true) {
                Context::addIf('span_id', Context::get('trace_id') ?? (string) Str::ulid());
            }

            if ($fields['origin'] ?? true) {
                Context::add('origin', 'cli');
            }

            // App identity — mirrors AddLogContext for CLI so log rows
            // dispatched from artisan (and any queue jobs chained from them)
            // carry app_name / app_env / app_url just like HTTP requests.
            if ($fields['app_name'] ?? true) {
                Context::addIf('app_name', (string) config('app.name'));
            }
            if ($fields['app_env'] ?? true) {
                Context::addIf('app_env', (string) config('app.env'));
            }
            if ($fields['app_url'] ?? true) {
                Context::addIf('app_url', (string) config('app.url'));
            }

            if ($fields['git_sha'] ?? true) {
                $sha = AddLogContext::resolveGitSha();
                if ($sha !== null) {
                    Context::addIf('git_sha', $sha);
                }
            }

            // Authenticated CLI user (rare but possible — e.g. a command that
            // impersonates a user before dispatching domain logic).
            if ($fields['user_id'] ?? true) {
                try {
                    if (Auth::hasUser()) {
                        Context::addIf('user_id', Auth::id());
                    }
                } catch (\Throwable) {
                    // Auth may not be bootable in every CLI context.
                }
            }

            Context::add('command_name', $event->command ?? 'unknown');

            if ($event->input) {
                $args = (string) $event->input;
                if ($args !== '') {
                    Context::add('command_args', Str::limit($args, 500));
                }
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event) use ($ignore, &$startTime): void {
            if (in_array($event->command, $ignore, true) || $startTime === null) {
                return;
            }

            $fields = config('owlogs.fields', []);

            if ($fields['duration_ms'] ?? true) {
                $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);
                Context::add('duration_ms', $durationMs);

                Context::push('measures', [
                    'label' => 'command',
                    'duration_ms' => (float) $durationMs,
                    'meta' => ['command' => $event->command],
                ]);
            }

            $startTime = null;
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
