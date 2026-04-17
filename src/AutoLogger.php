<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Auth\Events\Failed as AuthFailed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Automatically logs Laravel lifecycle events.
 *
 * Each event category can be toggled via config('owlogs.auto_log.*').
 */
class AutoLogger
{
    public function register(): void
    {
        $config = config('owlogs.auto_log', []);

        if (! config('owlogs.enabled', true)) {
            return;
        }

        $this->registerJobListeners($config);
        $this->registerAuthListeners($config);
        $this->registerMailListeners($config);
        $this->registerNotificationListeners($config);
        $this->registerDatabaseListeners($config);
        $this->registerCacheListeners($config);
        $this->registerHttpClientListeners($config);
        $this->registerSchedulerListeners($config);
        $this->registerModelListeners($config);
        $this->registerEventDispatchListener($config);
    }

    // ── Jobs ─────────────────────────────────────────────────────────────

    private function registerJobListeners(array $config): void
    {
        if ($config['job_dispatched'] ?? false) {
            Event::listen(JobQueued::class, function (JobQueued $event): void {
                $jobClass = $this->resolveJobClass($event->job);

                if ($this->isInternalJob($jobClass)) {
                    return;
                }

                Log::info('job.dispatched: '.class_basename($jobClass), [
                    'job' => $jobClass,
                    'queue' => $event->job->queue ?? 'default',
                    'connection' => $event->connectionName,
                    'delay' => $event->job->delay ?? null,
                ]);
            });
        }

        if ($config['job_started'] ?? false) {
            Event::listen(JobProcessing::class, function (JobProcessing $event): void {
                $jobClass = $event->job->resolveName();

                if ($this->isInternalJob($jobClass)) {
                    return;
                }

                Log::info('job.started: '.class_basename($jobClass), [
                    'job' => $jobClass,
                    'attempt' => $event->job->attempts(),
                    'queue' => $event->job->getQueue(),
                    'connection' => $event->connectionName,
                ]);
            });
        }

        if ($config['job_completed'] ?? false) {
            Event::listen(JobProcessed::class, function (JobProcessed $event): void {
                $jobClass = $event->job->resolveName();

                if ($this->isInternalJob($jobClass)) {
                    return;
                }

                Log::info('job.completed: '.class_basename($jobClass), [
                    'job' => $jobClass,
                    'attempt' => $event->job->attempts(),
                    'queue' => $event->job->getQueue(),
                ]);
            });
        }

        if ($config['job_failed'] ?? false) {
            Event::listen(JobFailed::class, function (JobFailed $event): void {
                $jobClass = $event->job->resolveName();

                if ($this->isInternalJob($jobClass)) {
                    return;
                }

                Log::error('job.failed: '.class_basename($jobClass).' — '.$event->exception->getMessage(), [
                    'job' => $jobClass,
                    'attempt' => $event->job->attempts(),
                    'queue' => $event->job->getQueue(),
                    'exception_class' => get_class($event->exception),
                    'exception_file' => $event->exception->getFile().':'.$event->exception->getLine(),
                ]);
            });

            Event::listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
                $jobClass = $event->job->resolveName();

                if ($this->isInternalJob($jobClass)) {
                    return;
                }

                Log::error('job.exception: '.class_basename($jobClass).' — '.$event->exception->getMessage(), [
                    'job' => $jobClass,
                    'attempt' => $event->job->attempts(),
                    'exception_class' => get_class($event->exception),
                    'exception_file' => $event->exception->getFile().':'.$event->exception->getLine(),
                ]);
            });
        }

        if ($config['job_retrying'] ?? false) {
            Event::listen(JobRetryRequested::class, function (JobRetryRequested $event): void {
                $jobClass = $event->job->resolveName();

                if ($this->isInternalJob($jobClass)) {
                    return;
                }

                Log::warning('job.retrying: '.class_basename($jobClass), [
                    'job' => $jobClass,
                    'delay' => $event->delay,
                ]);
            });
        }
    }

    // ── Auth ─────────────────────────────────────────────────────────────

    private function registerAuthListeners(array $config): void
    {
        if ($config['auth_login'] ?? false) {
            Event::listen(Login::class, function (Login $event): void {
                Log::info('auth.login: '.($event->user->email ?? $event->user->getKey()), [
                    'user_id' => $event->user->getKey(),
                    'guard' => $event->guard,
                    'remember' => $event->remember,
                    'ip' => request()->ip(),
                    'user_agent' => Str::limit((string) request()->userAgent(), 100, ''),
                ]);
            });
        }

        if ($config['auth_failed'] ?? false) {
            Event::listen(AuthFailed::class, function (AuthFailed $event): void {
                $email = $event->credentials['email'] ?? $event->credentials['username'] ?? '?';
                Log::warning('auth.failed: '.$email, [
                    'guard' => $event->guard,
                    'ip' => request()->ip(),
                    'user_agent' => Str::limit((string) request()->userAgent(), 100, ''),
                ]);
            });
        }

        if ($config['auth_logout'] ?? false) {
            Event::listen(Logout::class, function (Logout $event): void {
                Log::info('auth.logout: '.($event->user?->email ?? $event->user?->getKey()), [
                    'user_id' => $event->user?->getKey(),
                    'guard' => $event->guard,
                ]);
            });
        }

        if ($config['auth_password_reset'] ?? false) {
            Event::listen(PasswordReset::class, function (PasswordReset $event): void {
                Log::info('auth.password_reset: '.($event->user->email ?? $event->user->getKey()), [
                    'user_id' => $event->user->getKey(),
                ]);
            });
        }

        if ($config['auth_verified'] ?? false) {
            Event::listen(Verified::class, function (Verified $event): void {
                Log::info('auth.email_verified: '.($event->user->email ?? $event->user->getKey()), [
                    'user_id' => $event->user->getKey(),
                ]);
            });
        }
    }

    // ── Mail ─────────────────────────────────────────────────────────────

    private function registerMailListeners(array $config): void
    {
        if ($config['mail_sent'] ?? false) {
            Event::listen(MessageSending::class, function (MessageSending $event): void {
                $message = $event->message;
                $to = $this->extractAddresses($message->getTo());

                Log::info('mail.sending: '.implode(', ', $to).' — '.$message->getSubject(), [
                    'to' => $to,
                    'from' => $this->extractAddresses($message->getFrom()),
                    'mailer' => $event->data['mailer'] ?? null,
                ]);
            });

            Event::listen(MessageSent::class, function (MessageSent $event): void {
                $message = $event->sent->getOriginalMessage();
                $to = $this->extractAddresses($message->getTo());

                Log::info('mail.sent: '.implode(', ', $to).' — '.$message->getSubject(), [
                    'to' => $to,
                    'message_id' => $message->getHeaders()->get('Message-ID')?->getBodyAsString(),
                ]);
            });
        }
    }

    // ── Notifications ────────────────────────────────────────────────────

    private function registerNotificationListeners(array $config): void
    {
        if ($config['notification_sent'] ?? false) {
            Event::listen(NotificationSent::class, function (NotificationSent $event): void {
                Log::info('notification.sent: '.class_basename($event->notification).' via '.$event->channel, [
                    'notification' => get_class($event->notification),
                    'notifiable_type' => get_class($event->notifiable),
                    'notifiable_id' => $event->notifiable->getKey(),
                ]);
            });
        }

        if ($config['notification_failed'] ?? false) {
            Event::listen(NotificationFailed::class, function (NotificationFailed $event): void {
                Log::error('notification.failed: '.class_basename($event->notification).' via '.$event->channel, [
                    'notification' => get_class($event->notification),
                    'notifiable_type' => get_class($event->notifiable),
                    'notifiable_id' => $event->notifiable->getKey(),
                    'error' => $event->data['message'] ?? $event->data['error'] ?? 'unknown',
                ]);
            });
        }
    }

    // ── Database ─────────────────────────────────────────────────────────

    private function registerDatabaseListeners(array $config): void
    {
        if ($config['slow_query'] ?? false) {
            $threshold = (float) ($config['slow_query_ms'] ?? 500);

            DB::listen(function (QueryExecuted $query) use ($threshold): void {
                if ($query->time < $threshold) {
                    return;
                }

                if (Str::contains($query->sql, ['log_entries'])) {
                    return;
                }

                Log::warning('db.slow_query: '.round($query->time).'ms — '.Str::limit($query->sql, 100), [
                    'sql' => Str::limit($query->sql, 500),
                    'duration_ms' => round($query->time, 2),
                    'connection' => $query->connectionName,
                    'caller' => $this->resolveQueryCaller(),
                ]);
            });
        }

        if ($config['db_transaction'] ?? false) {
            Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
                Log::debug('db.transaction.committed', [
                    'connection' => $event->connectionName,
                ]);
            });
        }

        if ($config['migration'] ?? false) {
            Event::listen(MigrationEnded::class, function (MigrationEnded $event): void {
                Log::info('db.migration', [
                    'migration' => get_class($event->migration),
                    'direction' => $event->method,
                ]);
            });
        }
    }

    // ── Cache ────────────────────────────────────────────────────────────

    private function registerCacheListeners(array $config): void
    {
        if ($config['cache_miss'] ?? false) {
            Event::listen(CacheMissed::class, function (CacheMissed $event): void {
                Log::debug('cache.miss', [
                    'key' => $event->key,
                    'store' => $event->storeName,
                ]);
            });
        }

        if ($config['cache_hit'] ?? false) {
            Event::listen(CacheHit::class, function (CacheHit $event): void {
                Log::debug('cache.hit', [
                    'key' => $event->key,
                    'store' => $event->storeName,
                ]);
            });
        }
    }

    // ── HTTP Client ──────────────────────────────────────────────────────

    private function registerHttpClientListeners(array $config): void
    {
        if (! ($config['http_client'] ?? false)) {
            return;
        }

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $response = $event->response;

            if ($response->status() < 400) {
                return;
            }

            Log::error('http.error: '.$response->status().' '.$event->request->method().' '.Str::limit($event->request->url(), 80), [
                'url' => Str::limit($event->request->url(), 200),
                'status' => $response->status(),
                'duration_ms' => $response->transferStats?->getTransferTime()
                    ? round($response->transferStats->getTransferTime() * 1000, 2)
                    : null,
                'response_body' => Str::limit($response->body(), 500),
            ]);
        });

        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            Log::error('http.connection_failed: '.$event->request->method().' '.Str::limit($event->request->url(), 80), [
                'url' => Str::limit($event->request->url(), 200),
            ]);
        });
    }

    // ── Scheduler ────────────────────────────────────────────────────────

    private function registerSchedulerListeners(array $config): void
    {
        if (! ($config['schedule'] ?? false)) {
            return;
        }

        Event::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event): void {
            $command = $event->task->command ?? $event->task->description ?? 'closure';

            Log::error('schedule.failed: '.$command.' — '.$event->exception->getMessage(), [
                'exception_class' => get_class($event->exception),
                'exception_file' => $event->exception->getFile().':'.$event->exception->getLine(),
            ]);
        });
    }

    // ── Model Changes ────────────────────────────────────────────────────

    private function registerModelListeners(array $config): void
    {
        if (! ($config['model_changes'] ?? false)) {
            return;
        }

        $allowedModels = $config['model_changes_models'] ?? null;

        Event::listen('eloquent.created:*', function (string $event, array $data) use ($allowedModels): void {
            $model = $data[0];
            $class = get_class($model);

            if ($allowedModels !== null && ! in_array($class, $allowedModels, true)) {
                return;
            }

            Log::debug('model.created: '.class_basename($class).'#'.$model->getKey(), [
                'model' => $class,
                'id' => $model->getKey(),
                'attributes' => $this->safeAttributes($model->getAttributes()),
            ]);
        });

        Event::listen('eloquent.updated:*', function (string $event, array $data) use ($allowedModels): void {
            $model = $data[0];
            $class = get_class($model);

            if ($allowedModels !== null && ! in_array($class, $allowedModels, true)) {
                return;
            }

            $dirty = $model->getDirty();
            $original = array_intersect_key($model->getOriginal(), $dirty);

            Log::debug('model.updated: '.class_basename($class).'#'.$model->getKey().' ['.implode(', ', array_keys($dirty)).']', [
                'model' => $class,
                'id' => $model->getKey(),
                'changed' => array_keys($dirty),
                'from' => $this->safeAttributes($original),
                'to' => $this->safeAttributes($dirty),
            ]);
        });

        Event::listen('eloquent.deleted:*', function (string $event, array $data) use ($allowedModels): void {
            $model = $data[0];
            $class = get_class($model);

            if ($allowedModels !== null && ! in_array($class, $allowedModels, true)) {
                return;
            }

            Log::info('model.deleted: '.class_basename($class).'#'.$model->getKey(), [
                'model' => $class,
                'id' => $model->getKey(),
            ]);
        });
    }

    // ── Event Dispatch (wildcard) ───────────────────────────────────────

    /** @var list<string> */
    private const IGNORED_EVENT_PREFIXES = [
        'eloquent.',
        'Illuminate\\',
        'Laravel\\',
        'Skeylup\\',
        'SocialiteProviders\\',
        'Stancl\\',
        'Livewire\\',
        'Filament\\',
        'Nwidart\\',
        'creating:', 'created:', 'updating:', 'updated:', 'deleting:', 'deleted:',
        'saving:', 'saved:', 'restoring:', 'restored:', 'replicating:',
        'trashed:', 'forceDeleting:', 'forceDeleted:',
        'bootstrapping:', 'bootstrapped:',
    ];

    private function registerEventDispatchListener(array $config): void
    {
        if (! ($config['event_dispatch'] ?? false)) {
            return;
        }

        Event::listen('*', function (string $eventName, array $data): void {
            foreach (self::IGNORED_EVENT_PREFIXES as $prefix) {
                if (str_starts_with($eventName, $prefix)) {
                    return;
                }
            }

            if (! class_exists($eventName)) {
                return;
            }

            $event = $data[0] ?? null;
            $context = [
                'event' => $eventName,
                'event_short' => class_basename($eventName),
            ];

            if (is_object($event)) {
                $context = array_merge($context, $this->extractEventData($event));
            }

            Log::info('event.dispatched: '.class_basename($eventName), $context);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function extractEventData(object $event): array
    {
        $data = [];
        $reflection = new \ReflectionClass($event);
        $file = $reflection->getFileName();

        if ($file !== false) {
            $basePath = base_path().'/';
            $data['event_file'] = str_starts_with($file, $basePath)
                ? substr($file, strlen($basePath))
                : $file;
        }

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            $value = $property->getValue($event);

            if ($value === null) {
                continue;
            }

            if (is_scalar($value)) {
                $data[$name] = is_string($value) ? Str::limit($value, 200) : $value;
            } elseif ($value instanceof Model) {
                $data[$name.'_type'] = class_basename($value);
                $data[$name.'_id'] = $value->getKey();
            } elseif ($value instanceof Collection) {
                $data[$name.'_count'] = $value->count();
            } elseif (is_array($value)) {
                $data[$name.'_count'] = count($value);
            } elseif (is_object($value)) {
                $data[$name.'_type'] = class_basename($value);
            }
        }

        if (method_exists($event, 'broadcastOn')) {
            $channels = $event->broadcastOn();
            if (is_array($channels)) {
                $data['broadcast_channels'] = array_map(fn ($c) => is_object($c) ? (string) $c : $c, $channels);
            }
        }

        return $data;
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function resolveJobClass(object $job): string
    {
        if (is_string($job)) {
            return $job;
        }

        return get_class($job);
    }

    /**
     * Jobs whose lifecycle events must never be logged via this agent.
     *
     * Logging a failure here would emit a Log::error() → another buffered
     * entry → another SendLogsJob dispatch → potentially another failure,
     * creating a feedback loop that drowns Horizon in retries.
     *
     * Covers both the agent's own jobs AND the owlogs server-side jobs so
     * any ingest/embedding failure stays local (visible in storage/logs and
     * Horizon failed jobs, not re-shipped through the agent).
     */
    private function isInternalJob(string $jobClass): bool
    {
        $internals = [
            'Skeylup\\OwlogsAgent\\',
            'Skeylup\\Owlogs\\',
            'Illuminate\\Queue\\',
            'Illuminate\\Broadcasting\\',
            'Illuminate\\Events\\',
            'Laravel\\Scout\\',
        ];

        foreach ($internals as $prefix) {
            if (str_starts_with($jobClass, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function resolveQueryCaller(): ?string
    {
        $basePath = base_path().'/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 25) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || str_contains($file, '/vendor/') || str_contains($file, '/packages/skeylup/')) {
                continue;
            }

            if (str_starts_with($file, $basePath)) {
                return substr($file, strlen($basePath)).':'.($frame['line'] ?? '?');
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractAddresses(?array $addresses): array
    {
        if ($addresses === null) {
            return [];
        }

        return array_map(
            fn ($address) => $address->getAddress(),
            $addresses,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function safeAttributes(array $attributes): array
    {
        $sensitive = ['password', 'secret', 'token', 'api_key', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

        foreach ($attributes as $key => &$value) {
            if (in_array($key, $sensitive, true)) {
                $value = '********';
            } elseif (is_string($value) && mb_strlen($value) > 200) {
                $value = Str::limit($value, 200);
            }
        }

        return $attributes;
    }
}
