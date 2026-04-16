# Owlogs Agent

Ship Laravel logs — with rich context, tracing IDs, caller location, queue job metadata, and sanitized request body — asynchronously to [**Owlogs**](https://www.owlogs.com).

Drop-in, zero-config, Octane-safe. Works with Laravel 11, 12 and 13.

---

## Requirements

- PHP **8.2+**
- Laravel **11**, **12** or **13**
- A queue driver (redis, database, sqs, etc.) — **strongly recommended** so log shipping never blocks a request
- An **Owlogs account** (free sign-up at [owlogs.com](https://www.owlogs.com)) to get a workspace API key

---

## Features

- **Automatic context enrichment** on every log entry: `trace_id`, `span_id`, `origin`, `user_id`, `tenant_id`, `app_env`, `git_sha`, `uri`, `route_name`, `route_action`, `ip`, `duration_ms`, etc.
- **Distributed tracing**: the same `trace_id` flows from an HTTP request into every queue job it dispatches, so you can reconstruct the full lifetime of a request.
- **Caller location**: each log entry carries the `file:line` and `Class@method` where `Log::*()` was actually called — not the framework frame.
- **Queue job metadata**: job class, attempt, queue, connection, plus the public (scalar) properties of the job payload.
- **Artisan command metadata**: command name and arguments for CLI origin logs.
- **Sanitized request input**: POST / PUT / PATCH bodies are captured with `password`, `secret`, `token`, `authorization`, `cookie`, `credit_card` values redacted.
- **Exception stacktraces** including up to 3 levels of chained exceptions.
- **Performance spans** via the `Measure` helper and optional automatic DB query tracking with N+1 detection.
- **Breadcrumbs** for action timelines.
- **Opt-in lifecycle auto-logging** for auth events, job lifecycle, mail, cache, slow queries, scheduled tasks, model changes, and more.
- **Async delivery** via a queue job with retries and exponential backoff — shipping never blocks the request.
- **Octane-safe**: no container / request injection into singletons, all state is reset between requests.

---

## Quickstart

```bash
composer require skeylup/owlogs-agent
php artisan vendor:publish --tag=owlogs-agent-config
```

Add your workspace API key in `.env` — you can grab it from your workspace's **API keys** page on [owlogs.com](https://www.owlogs.com):

```env
OWLOGS_API_KEY=owl_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

Register the `owlogs` channel in `config/logging.php`:

```php
'channels' => [
    // ...

    'owlogs' => [
        'driver' => 'custom',
        'via'    => \Skeylup\OwlogsAgent\Handlers\RemoteLogChannel::class,
        'level'  => env('LOG_LEVEL', 'debug'),
        'tap'    => [\Skeylup\OwlogsAgent\LogContextTap::class],
    ],

    'stack' => [
        'driver'            => 'stack',
        'channels'          => explode(',', (string) env('LOG_STACK', 'single,owlogs')),
        'ignore_exceptions' => false,
    ],
],
```

Set `LOG_CHANNEL=stack` (or `LOG_CHANNEL=owlogs` if you only want remote shipping) and any `Log::info(...)`, `Log::error(...)` etc. will be sent to the Owlogs server asynchronously via a queue job.

If `OWLOGS_API_KEY` is empty, the queue job is still dispatched but returns silently without hitting the network — useful for local development.

---

## How it works

```
┌──────────────┐   ┌───────────────┐   ┌───────────────┐   ┌──────────────┐
│  Log::info() │ → │  LogContext   │ → │ RemoteHandler │ → │ SendLogsJob  │
│              │   │     Tap       │   │  (buffered)   │   │  (queued)    │
└──────────────┘   └───────────────┘   └───────────────┘   └──────┬───────┘
                                                                   │
                                                                   ▼
                                                          POST /api/owlogs/ingest
                                                          X-Api-Key: owl_…
```

1. Global middleware `AddLogContext` populates Laravel's `Context` facade on every HTTP request with the tracing, routing, user, tenant and timing fields.
2. `LogContextTap` attaches a Monolog processor that resolves the **real** caller frame (skipping framework internals) and sets a JSON formatter.
3. `RemoteHandler` buffers log records in memory (default 50) and flushes either on batch-size, on `register_shutdown_function`, or after each queue job completes (`Queue::after`).
4. Flushing dispatches a `SendLogsJob` that `POST`s the batch as JSON to `https://www.owlogs.com/api/owlogs/ingest` with `X-Api-Key: {OWLOGS_API_KEY}`.
5. The job retries 5 times with backoff `[2, 5, 15, 60, 180]` seconds on 5xx / network errors, and abandons on 4xx (bad key, invalid payload).

---

## Enriching logs from your models

Implement `HasLogContext` on any Eloquent model to expose safe, curated metadata in logs — instead of dumping the whole model.

```php
use Skeylup\OwlogsAgent\Contracts\HasLogContext;

class User extends Authenticatable implements HasLogContext
{
    public function toLogContext(): array
    {
        return [
            'email' => $this->email,
            'role'  => $this->role,
            'plan'  => $this->subscription?->plan_name,
        ];
    }

    public function getLogContextLabel(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
```

When the authenticated user implements this interface, the data is attached to every log entry under `extra.user` and `extra.user_label`. When passed explicitly as context (`Log::info('...', ['user' => $user])`), the same transformation happens — no more accidental serialization of sensitive fields.

---

## Performance measurement

```php
use Skeylup\OwlogsAgent\Measure;

// Manual span
Measure::start('generate_invoice', ['invoice_id' => 42]);
// ... work ...
Measure::stop('generate_invoice');

// Closure wrapper
$result = Measure::track('stripe.charge', fn () => Stripe::charge($cents));

// Instant checkpoint
Measure::checkpoint('cache.hit', ['key' => 'user:42']);
```

Spans are stored on Laravel's `Context` and shipped with the last log entry of the flushed batch.

Enable automatic DB query tracking with:

```env
OWLOGS_MEASURE_DB=true
OWLOGS_N_PLUS_ONE_THRESHOLD=5
```

Every query is recorded as a `db` span, and when the same normalized SQL runs more than N times an `n+1` marker is added to the measures array.

---

## Breadcrumbs

```php
use Skeylup\OwlogsAgent\Breadcrumb;

Breadcrumb::add('CreateProjectAction::execute');
Breadcrumb::add('ValidateBilling', 'plan=pro');
Breadcrumb::add('NotifyTeam');
```

Breadcrumbs are persisted as a `breadcrumbs` JSON array on every log entry of the request/job — so when something fails, you know exactly what led to it.

---

## Auto-logging lifecycle events

Enable any combination of the following in `.env` — all default to `false`:

| Env var | Event |
|---|---|
| `OWLOGS_AUTO_JOB_DISPATCHED`  | A job is queued |
| `OWLOGS_AUTO_JOB_STARTED`     | A worker picks up a job |
| `OWLOGS_AUTO_JOB_COMPLETED`   | A job completes successfully |
| `OWLOGS_AUTO_JOB_FAILED`      | A job fails (exception + attempt) |
| `OWLOGS_AUTO_JOB_RETRYING`    | A retry is requested |
| `OWLOGS_AUTO_AUTH_LOGIN`      | User logs in (email, IP, UA) |
| `OWLOGS_AUTO_AUTH_FAILED`     | Failed login attempt |
| `OWLOGS_AUTO_AUTH_LOGOUT`     | User logs out |
| `OWLOGS_AUTO_AUTH_PASSWORD_RESET` | Password reset completed |
| `OWLOGS_AUTO_AUTH_VERIFIED`   | Email verified |
| `OWLOGS_AUTO_MAIL_SENT`       | Mail sending / sent |
| `OWLOGS_AUTO_NOTIFICATION_SENT` | Notification dispatched |
| `OWLOGS_AUTO_NOTIFICATION_FAILED` | Notification failed |
| `OWLOGS_AUTO_SLOW_QUERY`      | Queries slower than `OWLOGS_AUTO_SLOW_QUERY_MS` (default 500) |
| `OWLOGS_AUTO_DB_TRANSACTION`  | DB transaction committed |
| `OWLOGS_AUTO_MIGRATION`       | Migration ran |
| `OWLOGS_AUTO_CACHE_HIT` / `OWLOGS_AUTO_CACHE_MISS` | Cache events |
| `OWLOGS_AUTO_HTTP_CLIENT`     | Outgoing HTTP client errors (>= 4xx) |
| `OWLOGS_AUTO_SCHEDULE`        | Scheduled task failed |
| `OWLOGS_AUTO_MODEL_CHANGES`   | Eloquent created / updated / deleted (scoped via `model_changes_models`) |
| `OWLOGS_AUTO_EVENT_DISPATCH`  | App-level events (excluding framework internals) |

---

## Full configuration reference

All of the following can be overridden in `config/owlogs.php` after publishing.

| Env var | Default | Description |
|---|---|---|
| `OWLOGS_ENABLED` | `true` | Master kill-switch |
| `OWLOGS_API_KEY` | — | Workspace API key (sent as `X-Api-Key`) |
| `OWLOGS_BATCH_SIZE` | `50` | Buffer size before a flush |
| `OWLOGS_QUEUE` | `default` | Queue name for `SendLogsJob` |
| `OWLOGS_QUEUE_CONNECTION` | — | Queue connection (null = app default) |
| `OWLOGS_TIMEOUT` | `30` | HTTP timeout in seconds |
| `OWLOGS_JSON` | `true` | Use JsonFormatter (vs. LineFormatter) |
| `OWLOGS_MEASURE_DB` | `false` | Auto-instrument DB queries |
| `OWLOGS_MEASURE_MEMORY` | `true` | Attach peak memory to each batch |
| `OWLOGS_N_PLUS_ONE_THRESHOLD` | `5` | Identical-SQL count to flag as N+1 |

Individual context fields can be toggled under `config('owlogs.fields')` if you want to opt out of e.g. `ip` or `user_agent`.

---

## Payload format

For transparency, here's what each flush POSTs to Owlogs:

```json
{
  "logs": [
    {
      "trace_id": "01JKXZ4…",
      "span_id": "01JKXZ4…",
      "origin": "http",
      "level_name": "ERROR",
      "level": 400,
      "channel": "owlogs",
      "message": "Payment declined",
      "stacktrace": "Stripe\\Exception\\CardException: …",
      "caller_file": "app/Http/Controllers/BillingController.php",
      "caller_line": 87,
      "caller_method": "BillingController@charge",
      "uri": "POST https://app.example.com/billing/charge",
      "route_name": "billing.charge",
      "route_action": "App\\Http\\Controllers\\BillingController@charge",
      "ip": "10.0.0.1",
      "user_agent": "Mozilla/5.0 …",
      "request_input": "{\"amount\":1000,\"currency\":\"EUR\"}",
      "user_id": 42,
      "tenant_id": 3,
      "app_name": "Example App",
      "app_env": "production",
      "app_url": "https://app.example.com",
      "git_sha": "a1b2c3d4",
      "job_class": null,
      "job_attempt": null,
      "queue_name": null,
      "connection_name": null,
      "duration_ms": 147,
      "context": null,
      "breadcrumbs": "[\"CreateOrderAction\",\"ChargeCard\"]",
      "job_props": null,
      "measures": "[{\"label\":\"stripe.charge\",\"duration_ms\":132.1}]",
      "memory_peak_mb": 38,
      "extra": "{\"user\":{\"email\":\"…\"}}",
      "logged_at": "2026-04-16 10:11:12.345"
    }
  ]
}
```

Expected responses:

- `202 Accepted` → `{"accepted": <count>}` — the agent moves on
- `4xx` → the job is **not retried** (fix the key / payload)
- `5xx` or network error → retried up to 5 times with backoff

---

## Troubleshooting

**Jobs pile up in the `failed_jobs` table.** Check the exception: if it's `401` / `403`, your `OWLOGS_API_KEY` is wrong or the key was rotated — regenerate it from your workspace and update `.env`.

**Logs never arrive.** Run `php artisan queue:work` — without a worker, dispatched `SendLogsJob` will never execute. Also verify `OWLOGS_API_KEY` is set (empty key = silent no-op).

**Octane complains about bindings.** The agent does not use container / request / config injection in singletons. If you see such warnings, they come from elsewhere in your app.

**Caller location is wrong.** If your logs go through a custom wrapper class, add its path to `config('owlogs.caller.ignore_paths')` so the frame-walker skips over it.

---

## Security

- **Redaction** is automatic for request body keys matching `password`, `password_confirmation`, `current_password`, `secret`, `token`, `key`, `authorization`, `cookie`, `credit_card`. Extend the list in `Middleware/AddLogContext.php` if you need more.
- **HTTPS**: traffic is sent over TLS to `https://www.owlogs.com` with Laravel's default HTTP client verification.
- **Authentication**: every request carries the `X-Api-Key` header. Rotate the key from your workspace and update `OWLOGS_API_KEY` to invalidate.
- **No global state**: all tracing IDs live in Laravel's `Context` which is reset between requests / jobs.

---

## License

[MIT](LICENSE) © [Skeylup](https://www.owlogs.com)
