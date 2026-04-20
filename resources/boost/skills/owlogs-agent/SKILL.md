---
name: owlogs-agent
description: 'ACTIVATE when the user asks to add logging, instrument a Laravel app, improve observability, trace workflows, add breadcrumbs, or when they mention owlogs, Owlogs, OWLOGS_, skeylup/owlogs-agent, Skeylup\OwlogsAgent, Breadcrumb::, Measure::, HasLogContext, or reference config/owlogs.php. Also activate when the user edits controllers, Livewire components, Actions, Services, Jobs, Listeners, Observers, or webhooks with the intent of adding Log::*, or asks ''where should I log this?'', ''add observability to this flow'', ''instrument these endpoints'', ''trace this request''. Guides the agent to add workflow-level logging that captures real business actions and decisions (state changes, external calls, branches, failures) and NOT data reads, view rendering, or framework internals. Relies on skeylup/owlogs-agent which auto-captures request / queue / command / exception context. Do NOT activate for generic log-level or logging-config questions unrelated to instrumenting app code, nor for other log shippers (Sentry, Flare, Bugsnag, Papertrail, ELK).'
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
- Opt-in auto-events (see `config/owlogs.php → auto_log`): auth, jobs, mail, notifications, slow queries, cache, schedule, model changes — toggled via `OWLOGS_AUTO_*` env vars.

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
- Steps already captured by a `Breadcrumb::add()` — don't double-log.
- Framework internals (middleware chains, container, config reads).
- Loops: one log for the batch outcome, never one per iteration unless an iteration fails.
- Debug statements ("got here", "value is X"). Use `Log::debug()` sparingly.

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
use Skeylup\OwlogsAgent\Breadcrumb;
use Skeylup\OwlogsAgent\Measure;
use Skeylup\OwlogsAgent\Contracts\HasLogContext;
```

- `Log::info('Order placed', ['order' => $order, 'amount_cents' => $order->total]);`
  Pass Eloquent models directly when they implement `HasLogContext` — curated, no accidental leak.
- `Breadcrumb::add('CreateOrderAction::execute');` — trail inside a flow without emitting a log row.
- `Measure::track('stripe.charge', fn () => $stripe->charges->create([...]));` — time an external call, attached to the request's measures.
- `Measure::checkpoint('feature_flag.resolved', ['flag' => 'new_checkout', 'value' => true]);` — instant marker.

## Breadcrumb rules

Breadcrumbs are the **silent trail** of a request / job — a flat list of strings that appears alongside every log row, answering "what steps happened before this?". Internally each call pushes a string onto `Context::push('breadcrumbs', ...)`.

### API
- `Breadcrumb::add(string $label, ?string $detail = null)` — pushes one entry. If `$detail` is given, the stored entry is `"{$label}: {$detail}"`.
- `Breadcrumb::all(): list<string>` — inspect the current trail.
- `Breadcrumb::clear(): void` — wipe it (very rare; context resets per request/job).

### When to drop one
- At the **entry of a meaningful method** in the request path: actions, services, listeners, observers, job `handle()`, webhook handlers.
- Just **before an important branch** when the path taken matters for post-mortem (`Breadcrumb::add('CheckoutFlow', 'variant=B')`).
- Each time a **boundary is crossed**: HTTP → queue dispatch, queue → external call, listener → service.

### Label format
- `ClassName@method` (mirrors PHP stacktrace style) or `ClassName::action` for static / semantic steps.
- `$detail` is a short string (≤80 chars): an event type, a slug, a decision — **never a payload**.

### Don't
- Don't breadcrumb inside tight loops — one entry for the batch, not per iteration.
- Don't breadcrumb every private helper — the trail becomes noise past ~20 entries per request.
- Don't put secrets, tokens, emails, SQL, JSON, or full IDs as the detail.
- Don't use breadcrumbs **instead** of logs — they have no message, no level, no metadata dict. A flow with 15 breadcrumbs should still emit 1–3 `Log::*` lines (entry / outcome / failure).

### Example
```php
public function handle(StripeEvent $event): void
{
    Breadcrumb::add('StripeWebhook@handle', $event->type);

    match ($event->type) {
        'invoice.paid'   => $this->onInvoicePaid($event),
        'invoice.failed' => $this->onInvoiceFailed($event),
        default          => Breadcrumb::add('StripeWebhook@ignored', $event->type),
    };
}
```

## Measure rules

Measures produce a **timeline of durations or instants** attached to the request / job, shipped in the `measures` JSON column. Use them to answer "where did the time go?" and "what decisions were taken?" without adding log noise.

### Three flavors — pick the right one

| API | Returns | Use when |
|---|---|---|
| `Measure::track(string $label, callable $cb, array $meta = [])` | closure result | **Default choice.** A closure returns a value and you want it timed. `try/finally` inside the method guarantees `stop` even on exception. Meta is fixed at start — if you need to attach **result-derived** meta (row count, status), use `start`/`stop` instead. |
| `Measure::start(string $label, array $meta = [])` + `Measure::stop(string $label, array $extraMeta = [])` | `?float` (ms) | Start and stop live in different methods or across an event boundary. Always pair them — unmatched `start` is silently discarded, unmatched `stop` returns `null`. Wrap manually in try/finally if the operation can throw. |
| `Measure::checkpoint(string $label, array $meta = [])` | void | Instant marker, no duration — a decision, a flag resolution, a cache hit/miss, an event you want visible in the trace without emitting a full `Log::*`. |

### Label convention — stable and low-cardinality

Labels are **aggregated server-side** (p50 / p95 / count). They must be stable across calls for the same operation.

- Format: `vendor.action` (dotted namespace). Examples: `stripe.charge`, `stripe.refund`, `openai.completion`, `s3.upload`, `s3.presign`, `intercom.identify`, `mail.render`, `pdf.generate`, `cache.invalidate`, `redis.pubsub`.
- For internal use-cases: `domain.action` — `invoice.calculate`, `report.build`, `import.parse`.
- **Never embed IDs in the label** (`user.42.load` is high-cardinality — puts 42 in the label space). Put IDs in `meta`.

### Meta — small and scalar

Meta is shipped as-is. Keep it tight:
- ✅ IDs (`['user_id' => 42, 'order_id' => $order->id]`)
- ✅ Counts / sizes (`['rows' => 120, 'bytes' => 48_512]`)
- ✅ Status / flags (`['status' => 'succeeded', 'currency' => 'EUR']`)
- ❌ Full payloads, arrays of models, serialized requests, JSON blobs.
- ❌ Tokens, passwords, full credit card numbers — same rules as logs.

### What to wrap

✅ **Wrap with `Measure`:**
- External I/O: HTTP client calls, Stripe SDK, S3/GCS, OpenAI/Anthropic API, Redis pub/sub, ElasticSearch, Meilisearch, mail transport, queue dispatch to external systems.
- Logical units that take real time: invoice calculation, PDF generation, bulk import, batch export, heavy aggregation, video transcode kick-off.
- Suspected hot paths you want to watch in prod (feature-flag rollout, new algorithm).

❌ **Do NOT wrap:**
- Individual DB queries — enable `OWLOGS_MEASURE_DB=true` instead (automatic span per query + N+1 detection). Only wrap the logical unit ("calc_invoice") that runs many queries.
- Cheap in-memory operations (array transforms, DTO construction).
- Inside tight loops — wrap the loop, not each iteration.
- Code already covered by the package (HTTP request total duration is captured automatically).

### checkpoint — use cases

`checkpoint` records a zero-duration marker with metadata. Prefer it over `Log::debug()` when you want the decision visible in the trace but without producing a log row.

```php
// Feature flag resolution
Measure::checkpoint('flag.resolved', ['flag' => 'new_checkout', 'value' => $enabled]);

// Cache decision point
$user = Cache::remember("user.$id", 300, function () use ($id) {
    Measure::checkpoint('cache.miss', ['key' => "user.$id"]);
    return User::findOrFail($id);
});

// Routing / business decision
Measure::checkpoint('order.routing', ['lane' => $isPriority ? 'priority' : 'standard']);

// Guard rail fired
Measure::checkpoint('rate_limit.hit', ['key' => $key, 'remaining' => 0]);
```

### `start` / `stop` — safe pattern

When start/stop straddle a boundary (e.g. controller → event → listener), always guard with try/finally so `stop` runs on exception:

```php
Measure::start('import.parse', ['file' => $file->getClientOriginalName(), 'bytes' => $file->getSize()]);
try {
    $rows = $this->parser->parse($file);
} finally {
    Measure::stop('import.parse', ['rows' => $rows ?? 0]);
}
```

For a single closure, `track` does this automatically — prefer it.

## Execution plan (run in order)

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

Before logging models, make them safe to serialize. For `User`, `Team`/`Tenant`/`Workspace`, and the main transactional entities (`Order`, `Invoice`, `Project`, `Document`, …), return only:
- Identifying fields (`id`, slug, external ref).
- Domain status (`status`, `plan`, `role`).
- A label from `getLogContextLabel()`.

**Never** secrets, tokens, raw PII beyond email, full addresses, full payloads.

```php
use Skeylup\OwlogsAgent\Contracts\HasLogContext;

class User extends Authenticatable implements HasLogContext
{
    public function toLogContext(): array
    {
        return ['email' => $this->email, 'role' => $this->role, 'plan' => $this->plan];
    }

    public function getLogContextLabel(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }
}
```

### 3. Instrument one workflow at a time

Pattern: **entry breadcrumb → action → outcome log**.

**Controller / Livewire / Action entry:**
```php
public function store(CreateOrderRequest $request): RedirectResponse
{
    Breadcrumb::add(static::class.'@store');
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
    Breadcrumb::add(static::class);
    $report = Measure::track('generate_report', fn () => $this->builder->build($this->periodId));
    Log::info('Monthly report generated', ['period' => $this->periodId, 'rows' => $report->rowCount()]);
}
```

**Webhook handler:**
```php
Breadcrumb::add('StripeWebhook@handle', $event->type);
Log::info('Webhook received', ['provider' => 'stripe', 'type' => $event->type, 'event_id' => $event->id]);
```

### 4. Catch failures that matter

Wherever a `try/catch` swallows an exception or converts it to a user message, add `Log::error('...', ['exception' => $e])` — otherwise that failure never makes it to the trace. Leave existing `report($e)` calls alone.

### 5. Enable relevant auto_log flags

Sensible defaults in `.env`:
```env
OWLOGS_AUTO_JOB_FAILED=true
OWLOGS_AUTO_AUTH_FAILED=true
OWLOGS_AUTO_SLOW_QUERY=true
OWLOGS_AUTO_SLOW_QUERY_MS=500
OWLOGS_AUTO_SCHEDULE=true
OWLOGS_AUTO_NOTIFICATION_FAILED=true
```
Enable `OWLOGS_AUTO_MODEL_CHANGES=true` + `model_changes_models` in `config/owlogs.php` **only** for domain-critical models (Subscription, Order, Invoice). Never all models.
Try `OWLOGS_MEASURE_DB=true` in staging first to find N+1s.

### 6. Verify

- Run a representative flow locally; confirm one `trace_id` produces a readable story: entry → breadcrumbs → measures → business logs → outcome.
- Re-read each added `Log::*` — if the message answers only "we reached line X", **delete it**.
- Confirm no log leaks secrets, tokens, full request bodies, or 3rd-party API responses that may contain keys.

## Deliverable to the user

1. Files modified, grouped by domain (Auth, Billing, Orders, …).
2. `OWLOGS_AUTO_*` flags enabled in `.env`.
3. Models that now implement `HasLogContext`.
4. Flows consciously skipped and why.

Keep edits minimal — do not refactor, rename, or reorganize code while instrumenting.
