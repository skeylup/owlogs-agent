---
name: owlogs-agent
description: 'ACTIVATE when the user asks to add logging, instrument a Laravel app, improve observability, trace workflows, or when they mention owlogs, Owlogs, OWLOGS_, skeylup/owlogs-agent, Skeylup\OwlogsAgent, Measure::, HasLogContext, or reference config/owlogs.php. Also activate when the user edits controllers, Livewire components, Actions, Services, Jobs, Listeners, Observers, or webhooks with the intent of adding Log::*, or asks ''where should I log this?'', ''add observability to this flow'', ''instrument these endpoints'', ''trace this request''. Guides the agent to add workflow-level logging that captures real business actions and decisions (state changes, external calls, branches, failures) and NOT data reads, view rendering, or framework internals. Relies on skeylup/owlogs-agent which auto-captures request / queue / command / exception context. Do NOT activate for generic log-level or logging-config questions unrelated to instrumenting app code, nor for other log shippers (Sentry, Flare, Bugsnag, Papertrail, ELK).'
license: MIT
metadata:
  author: skeylup
  homepage: https://www.owlogs.com
---

# Owlogs Agent — workflow instrumentation

You are adding logging to a Laravel codebase that uses `skeylup/owlogs-agent`.

## What the package already does (do NOT duplicate)

- Enriches every log line with `trace_id`, `span_id`, `origin`, `user_id`, route, IP, duration, git SHA, sanitized request body.
- Propagates the same `trace_id` from HTTP → queue jobs → artisan commands.
- Captures queue job class / attempt / queue / connection, artisan command name + args.
- Attaches exception stacktraces on `Log::error(..., ['exception' => $e])` and `report()`.
- Auto-events enabled by default (see `config/owlogs.php → auto_log`): auth, jobs, mail, notifications, slow queries, HTTP client calls, incoming error responses, middleware rejections, model changes, app events, `route.matched`, Livewire calls and GraphQL operations. `migration`, `schedule`, `db.transaction.*`, and cache hits / misses are opt-in. Toggle via `OWLOGS_AUTO_*` env vars.
- Configurable auto-log sources worth knowing:
  - `OWLOGS_AUTO_ROUTE_MATCHED` (on by default) — emits `route.matched` when the router resolves the route.
  - `OWLOGS_AUTO_DB_TRANSACTION` (off by default) — emits `db.transaction.committed` / `db.transaction.rolled_back`.
  - `OWLOGS_AUTO_LIVEWIRE_CALL` (on by default) — emits a debug line per Livewire component method call; set it to `false` to keep only the `livewire_calls` context.
- **If a step deserves to appear in the trace timeline, emit an explicit `Log::info('[step.name]', [...])` — correlation by `trace_id` produces the same chronological narrative without any separate "trail" abstraction.**

Your job is to instrument the **application layer** — the part the package cannot see — so reading one `trace_id` reconstructs the business story.

## Golden rule: log actions and decisions, not reads

A log line must answer **"what just changed, was decided, or left this app?"** — not "what did we load?".

### ✅ Log (state-changing or externally observable)
- Resource created / updated / deleted (Order placed, Subscription cancelled, File deleted).
- Decision / branch that changes the outcome (fraud check passed/failed, feature flag resolved, routing decision).
- Payment, charge, refund, webhook processed.
- External call leaving the app (Stripe, 3rd-party API, outbound webhook) — and its result.
- Authorization check **fails** (policy denied, rate-limit hit).
- Domain event dispatched.
- Async job dispatched or completed with a business-meaningful result.
- Guardrail fires (circuit breaker open, retries exhausted, quota exceeded).
- User-facing error (validation batch rejected, import aborted).

### ❌ Do NOT log
- Reads (`Model::find`, `->get()`, `->paginate()`) unless the read itself *is* the business action (exports, reports).
- View rendering, Blade partial loads, Livewire hydration, `mount()` / `render()`.
- Steps already captured by the auto-log framework (`route.matched`, `mail.sent`, `job.started`, `db.transaction.committed`, etc.) — don't duplicate them with explicit `Log::*`.
- Framework internals (middleware chains, container, config reads).
- Loops: one log for the batch outcome, never one per iteration unless an iteration fails.
- Debug statements ("got here", "value is X"). Use `Log::debug()` sparingly.

## Never put this in any log payload

Regardless of the call site (`Log::*`, `Measure::*` meta, `toLogContext()` return) — these never go in:

- Secrets: API keys, tokens, JWTs, `.env` values, hashed passwords, 2FA seeds, signing secrets, OAuth state nonces.
- Full credit-card numbers, full IBANs, CVV — last 4 digits at most.
- Raw request bodies, raw 3rd-party API responses (they routinely contain keys the local test fixture doesn't show).
- Full PII beyond the log's purpose: a "user changed plan" log doesn't need full address; a "shipping label printed" log does.
- Closures, resources, file handles, `SplObjectStorage`.
- Arrays of full Eloquent models — use `HasLogContext`, or pass `['ids' => [...]]` for bulk.
- Customer-uploaded file contents (only filename + size + sha256).

Rule of thumb: if you'd be uncomfortable seeing the row pasted into a shared Slack channel, redact before logging.

## Log level choice

| Level | When |
|---|---|
| `Log::info()` | Successful business action happened — default for state changes. |
| `Log::warning()` | Recoverable anomaly (retryable external error, degraded mode, quota close to cap). |
| `Log::error()` | Business action failed, user got a broken outcome, guardrail tripped. Use `['exception' => $e]` or `report($e)` to ship the stacktrace. |
| `Log::critical()` | Data integrity at risk, payment discrepancy, auth bypass. |
| `Log::debug()` | Off in prod (`LOG_LEVEL=info`). Keep for high-churn internal flows needing post-mortem. |

## Tooling cheat-sheet

```php
use Illuminate\Support\Facades\Log;
use Skeylup\OwlogsAgent\Measure;
use Skeylup\OwlogsAgent\Contracts\HasLogContext;
```

- `Log::info('Order placed', ['order' => $order, 'amount_cents' => $order->total]);`
  Pass Eloquent models directly when they implement `HasLogContext` — curated, no accidental leak.
- `Measure::track('stripe.charge', fn () => $stripe->charges->create([...]));` — time an external call, attached to the request's measures.
- `Measure::checkpoint('feature_flag.resolved', ['flag' => 'new_checkout', 'value' => true]);` — instant marker.

## Reference files — read the one you need

- [references/measure.md](references/measure.md): `Measure::track` / `start` / `stop` / `checkpoint`, label convention, meta, what to wrap.
- [references/has-log-context.md](references/has-log-context.md): how the package consumes `HasLogContext`, what `toLogContext()` returns, priority order, checklist, multi-tenant apps.
- [references/per-layer.md](references/per-layer.md): where the log goes per layer — Livewire, GraphQL (Lighthouse), controllers, actions / services, inheritance, jobs, scheduled tasks, listeners / observers, mail, middleware, validation, webhooks, admin / impersonation, bulk, files, tests.

## Execution plan (run in order)

> Use this as the macro plan. For per-layer contracts (Livewire, Services, Jobs, Schedule, Listeners, Webhooks, Admin/Impersonation, Bulk, Files), follow [references/per-layer.md](references/per-layer.md).

### 1. Discover the workflows

List candidates with Grep/Glob:
- `app/Http/Controllers/**/*.php` — HTTP entry points.
- `app/Livewire/**/*.php` — Livewire actions (methods, not `mount` / `render`).
- `app/Actions/**/*.php`, `app/Services/**/*.php`, `app/UseCases/**/*.php` — business logic.
- `app/Jobs/**/*.php` — async work.
- `app/Console/Commands/**/*.php` — cron / ops.
- `app/Listeners/**/*.php`, `app/Observers/**/*.php` — reactive code.
- `routes/` — search for `webhook`, `stripe`, `github`, etc.

Pick the top 15–25 **business-meaningful** entry points. Skip read-only controllers (`index`, `show`).

### 2. Implement `HasLogContext` on key models first

Follow [references/has-log-context.md](references/has-log-context.md). Priority order: `User` → tenant/team → main transactional entities. Minimum for the User model:

```php
use Skeylup\OwlogsAgent\Contracts\HasLogContext;

class User extends Authenticatable implements HasLogContext
{
    public function toLogContext(): array
    {
        return [
            'id'    => $this->getKey(),
            'email' => $this->email,
            'role'  => $this->role,
            'plan'  => $this->plan,
        ];
    }

    public function getLogContextLabel(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: $this->email;
    }
}
```

Once this is in place, every request automatically carries the user info — **you don't need to pass `['user' => $user]` anywhere**.

### 3. Instrument one workflow at a time

Pattern: **action → outcome log**. If any intermediate step deserves the timeline, add its own `Log::info('[step.name]', [...])`.

**Controller / Livewire / Action entry:**
```php
public function store(CreateOrderRequest $request): RedirectResponse
{
    $order = Measure::track('create_order', fn () => $this->createOrder->handle($request->validated()));
    Log::info('Order placed', [
        'order' => $order,
        'amount_cents' => $order->total,
        'payment_method' => $request->input('payment_method'),
    ]);
    return redirect()->route('orders.show', $order);
}
```

**External call:**
```php
try {
    $charge = Measure::track('stripe.charge', fn () => $this->stripe->charges->create([...]), ['cents' => $cents]);
    Log::info('Payment captured', ['charge_id' => $charge->id, 'cents' => $cents]);
} catch (\Stripe\Exception\CardException $e) {
    Log::warning('Payment declined', ['code' => $e->getStripeCode(), 'cents' => $cents]);
    throw $e;
}
```

**Decision branch:**
```php
if ($user->isOnTrial() && $trialExpired) {
    Log::info('Trial expired, downgrading', ['user' => $user, 'days_over' => $daysOver]);
    $this->downgrade($user);
}
```

**Job body** (auto-events already cover dispatch/fail — log the business outcome):
```php
public function handle(): void
{
    $report = Measure::track('generate_report', fn () => $this->builder->build($this->periodId));
    Log::info('Monthly report generated', ['period' => $this->periodId, 'rows' => $report->rowCount()]);
}
```

**Webhook handler:**
```php
Log::info('Webhook received', ['provider' => 'stripe', 'type' => $event->type, 'event_id' => $event->id]);
```

### 4. Catch failures that matter

Wherever a `try/catch` swallows an exception or converts it to a user message, add `Log::error('...', ['exception' => $e])` — otherwise that failure never makes it to the trace. Leave existing `report($e)` calls alone.

### 5. Tune auto_log flags

Most categories are on by default — no `.env` changes needed for jobs, auth, mail, notifications, slow queries, HTTP client calls, error responses, model changes, app events.

Opt-in extras (disabled by default):
```env
OWLOGS_AUTO_MIGRATION=true   # migrations — noisy on deploys, enable if you audit them
OWLOGS_AUTO_SCHEDULE=true    # scheduled task failures
OWLOGS_AUTO_DB_TRANSACTION=true  # commits / rollbacks — noisy, for debugging silent rollbacks
```

`OWLOGS_AUTO_MODEL_CHANGES` is already `true`, but **you must still populate** `auto_log.model_changes_models` in `config/owlogs.php` — restrict it to your domain-critical models (e.g. `Subscription`, `Order`, `Invoice`). Never leave it unscoped for all models.
Try `OWLOGS_MEASURE_DB=true` in staging first to find N+1s.

### 6. Verify

- Run a representative flow locally; confirm one `trace_id` produces a readable story: auto-logs (`route.matched`, etc.) → measures → business logs → outcome.
- Re-read each added `Log::*` — if the message answers only "we reached line X", **delete it**.
- Confirm no log leaks secrets, tokens, full request bodies, or 3rd-party API responses that may contain keys.

## Deliverable to the user

1. Files modified, grouped by domain (Auth, Billing, Orders, …).
2. `OWLOGS_AUTO_*` flags enabled in `.env`.
3. Models that now implement `HasLogContext`.
4. Flows consciously skipped and why.

Keep edits minimal — do not refactor, rename, or reorganize code while instrumenting.
