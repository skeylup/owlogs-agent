<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
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
use Laravel\Octane\Events\WorkerStarting;
use Laravel\Octane\Events\WorkerStopping;
use Skeylup\OwlogsAgent\Flushing\EndOfRequestPolicy;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Flushing\OctaneWindowPolicy;
use Skeylup\OwlogsAgent\Flushing\RuntimeDetector;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Handlers\RemoteLogChannel;
use Skeylup\OwlogsAgent\Middleware\AddLogContext;
use Skeylup\OwlogsAgent\Transport\FileLogBufferStore;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Skeylup\OwlogsAgent\Transport\RedisLogBufferStore;

class OwlogsAgentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/owlogs.php',
            'owlogs'
        );

        $this->app->singleton(FlushPolicy::class, function (): FlushPolicy {
            $override = config('owlogs.transport.flush_strategy');

            if ($override === 'octane') {
                return new OctaneWindowPolicy;
            }

            if ($override === 'end_of_request') {
                return new EndOfRequestPolicy;
            }

            return RuntimeDetector::isOctane()
                ? new OctaneWindowPolicy
                : new EndOfRequestPolicy;
        });

        $this->app->singleton(LogBufferStore::class, function (): LogBufferStore {
            $driver = (string) config('owlogs.transport.buffer_store', 'redis');

            if ($driver === 'file') {
                $path = (string) (config('owlogs.transport.file_path')
                    ?: storage_path('app/owlogs/buffer.jsonl'));

                return new FileLogBufferStore($path);
            }

            if ($driver === 'memory') {
                return new InMemoryLogBufferStore;
            }

            return new RedisLogBufferStore(
                (string) config('owlogs.transport.redis_connection', 'default'),
                (string) config('owlogs.transport.redis_key', 'owlogs:buffer'),
            );
        });
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
        $this->registerScheduleContext();
        $this->registerAutoInstrumentation();
        $this->registerAutoLogger();
        $this->registerFlushHooks();

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

        // Job-boundary flush: delegates to the active FlushPolicy so the
        // Octane batch window is respected under Octane (task workers) and
        // classic workers force-flush via EndOfRequestPolicy.
        Queue::after(function (JobProcessed $event): void {
            $this->onRequestBoundary();
        });
    }

    /**
     * Wire runtime-appropriate flush triggers.
     *
     * - Non-Octane (PHP-FPM / classic CLI / queue:work) → Laravel's
     *   terminating() hook + the shutdown function already registered in
     *   RemoteHandler::write() cover end-of-request.
     * - Octane (all servers) → RequestTerminated, TaskTerminated,
     *   WorkerStopping delegate to the policy (check the 2s / 20-log
     *   window, not a force flush).
     * - Octane on Swoole → Octane::tick fires every second so records
     *   emitted during an idle period still ship within the window.
     */
    private function registerFlushHooks(): void
    {
        if (RuntimeDetector::isOctane()) {
            $this->registerOctaneFlushHooks();

            return;
        }

        $this->app->terminating(function (): void {
            $this->onRequestBoundary();
        });
    }

    private function registerOctaneFlushHooks(): void
    {
        if (class_exists(RequestTerminated::class)) {
            Event::listen(RequestTerminated::class, fn () => $this->onRequestBoundary());
        }

        if (class_exists(TaskTerminated::class)) {
            Event::listen(TaskTerminated::class, fn () => $this->onRequestBoundary());
        }

        if (class_exists(WorkerStopping::class)) {
            Event::listen(WorkerStopping::class, fn () => $this->onWorkerStopping());
        }

        if (class_exists(WorkerStarting::class) && RuntimeDetector::octaneServer() === 'swoole') {
            Event::listen(WorkerStarting::class, function (): void {
                $facade = 'Laravel\\Octane\\Facades\\Octane';
                if (! class_exists($facade)) {
                    return;
                }

                try {
                    $facade::tick('owlogs-flush', function (): void {
                        $this->onTick();
                    })->seconds(1)->immediate();
                } catch (\Throwable) {
                    // Tick registration is best-effort; failures must not
                    // block worker startup.
                }
            });
        }
    }

    private function onRequestBoundary(): void
    {
        $handler = $this->resolveRemoteHandler();
        if ($handler === null) {
            return;
        }

        app(FlushPolicy::class)->onRequestBoundary($handler);
    }

    private function onWorkerStopping(): void
    {
        $handler = $this->resolveRemoteHandler();
        if ($handler === null) {
            return;
        }

        app(FlushPolicy::class)->onWorkerStopping($handler);
    }

    private function onTick(): void
    {
        $handler = $this->resolveRemoteHandler();
        if ($handler === null) {
            return;
        }

        app(FlushPolicy::class)->onTick($handler);
    }

    /**
     * Resolve the RemoteHandler attached to the `owlogs` channel.
     * Returns null when the channel isn't wired up (e.g. non-http/queue
     * contexts, or the user has replaced the channel definition).
     */
    private function resolveRemoteHandler(): ?RemoteHandler
    {
        try {
            $channel = app('log')->channel('owlogs');
            $monolog = $channel->getLogger();
            foreach ($monolog->getHandlers() as $handler) {
                if ($handler instanceof RemoteHandler) {
                    return $handler;
                }
            }
        } catch (\Throwable) {
            // Channel may not exist in all configurations.
        }

        return null;
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

            $this->onRequestBoundary();
        });
    }

    /**
     * Register context enrichment for scheduled tasks (Schedule::job/call/command).
     *
     * Without this, jobs dispatched via Schedule::job() inherit an empty Context
     * because schedule:run is excluded from CommandStarting handling — the job is
     * dispatched inside the schedule:run process before any trace_id is set.
     */
    private function registerScheduleContext(): void
    {
        if (! config('owlogs.commands.enabled', true)) {
            return;
        }

        $startTime = null;

        Event::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) use (&$startTime): void {
            $fields = config('owlogs.fields', []);
            $startTime = hrtime(true);

            if ($fields['trace_id'] ?? true) {
                Context::add('trace_id', (string) Str::ulid());
            }

            if ($fields['span_id'] ?? true) {
                Context::add('span_id', Context::get('trace_id') ?? (string) Str::ulid());
            }

            if ($fields['origin'] ?? true) {
                Context::add('origin', 'schedule');
            }

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

            Context::add('scheduled_task', $event->task->getSummaryForDisplay());
        });

        $cleanup = function ($event) use (&$startTime): void {
            $fields = config('owlogs.fields', []);

            if (($fields['duration_ms'] ?? true) && $startTime !== null) {
                $durationMs = (int) round((hrtime(true) - $startTime) / 1_000_000);
                Context::add('duration_ms', $durationMs);

                Context::push('measures', [
                    'label' => 'scheduled_task',
                    'duration_ms' => (float) $durationMs,
                    'meta' => ['task' => $event->task->getSummaryForDisplay()],
                ]);
            }

            $startTime = null;

            $this->onRequestBoundary();

            foreach (['trace_id', 'span_id', 'origin', 'scheduled_task', 'duration_ms'] as $key) {
                Context::forget($key);
            }
        };

        Event::listen(ScheduledTaskFinished::class, $cleanup);
        Event::listen(ScheduledTaskFailed::class, $cleanup);
        Event::listen(ScheduledTaskSkipped::class, $cleanup);
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
