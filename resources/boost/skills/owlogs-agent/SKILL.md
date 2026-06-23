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
- Auto-events enabled by default (see `config/owlogs.php → auto_log`): auth, jobs, mail, notifications, slow queries, cache, HTTP client errors, model changes, app events, `route.matched`. `migration`, `schedule`, `db.transaction.*`, and `livewire.call` are opt-in. Toggle via `OWLOGS_AUTO_*` env vars.
- Configurable auto-log sources worth knowing:
  - `OWLOGS_AUTO_ROUTE_MATCHED` (on by default) — emits `route.matched` when the router resolves the route.
  - `OWLOGS_AUTO_DB_TRANSACTION` (off by default) — emits `db.transaction.committed` / `db.transaction.rolled_back`.
  - `OWLOGS_AUTO_LIVEWIRE_CALL` (off by default) — emits `livewire.call: Component::method` for each Livewire action.
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

## HasLogContext rules — enriching log rows with model info

`HasLogContext` is the **only safe way** to attach rich domain-model information to a log row. Without it, models either leak everything (default Eloquent serialization) or show up as a useless `{_model, id}` stub.

### How the package consumes it (verified against source)

Two entry points in the package:

**A. Authenticated user — automatic on every HTTP request.**
In [AddLogContext.php:137-140](src/Middleware/AddLogContext.php), if `auth()->user() instanceof HasLogContext`:
```php
Context::add('user_context', $user->toLogContext());
Context::add('user_label', $user->getLogContextLabel());
```
→ lands in every log row as `extra.user` + `extra.user_label`. Zero call-site cost. A fallback in [RemoteHandler.php:315-319](src/Handlers/RemoteHandler.php) also resolves `auth()->user()` at log-write time for contexts where the middleware didn't run (queue, command).

**B. Explicit context — any key you pass to `Log::*()`.**
In [RemoteHandler.php:338-353](src/Handlers/RemoteHandler.php), `transformContext()` walks the context array recursively:

| Value type | Replaced by | Safe? | Useful? |
|---|---|---|---|
| `HasLogContext` instance | `$value->toLogContext()` | ✅ | ✅ rich |
| Eloquent `Model` (no interface) | `['_model' => FQCN, 'id' => $key]` | ✅ | ⚠️ minimal |
| Other object | `['_class' => FQCN]` | ✅ | ❌ dead weight |
| Array | recursed into | — | — |
| Scalar / null | left alone | ✅ | ✅ |

So `Log::info('Order refunded', ['order' => $order])` only gives you usable data **if `Order` implements `HasLogContext`**. Otherwise you just get the ID and have to join back to the DB by hand.

### What to return from `toLogContext()`

✅ **Do:**
- Scalars: IDs, slug, status, plan, role, email, counts.
- Flat arrays of scalars: `['roles' => ['admin', 'billing']]`.
- Dates pre-formatted as ISO strings: `$this->created_at?->toIso8601String()`.
- External references: `stripe_customer_id`, `external_ref`.

❌ **Don't:**
- **Raw relations** (`$this->team`, `$this->items`). `transformContext()` does NOT recurse into what `toLogContext()` returns — a nested model will be JSON-serialized verbatim and may leak everything. Flatten: `'team_id' => $this->team_id, 'team_name' => $this->team?->name`.
- Raw `Carbon`/`DateTime` — serialize manually.
- Secrets, tokens, 2FA seeds, full credit-card numbers, hashed passwords.
- PII beyond what's strictly needed (email yes; full address / phone only if the log is about shipping / support).
- Closures, resources, `SplObjectStorage`.

### `getLogContextLabel()` — user-only, for now

The package currently only ships the label for `auth()->user()` (as `extra.user_label`). For other models the method is unused but still required by the interface — return a reasonable human label anyway (future-proofing + useful for local debugging via `$model->getLogContextLabel()`).

### Prioritisation — implement in this order

1. **`User`** — hit on every request, biggest ROI. Do this first.
2. **Tenant / Team / Workspace** — if multi-tenant, pass it explicitly: `Log::info('X', ['tenant' => $tenant])`.
3. **Main transactional entities** — `Order`, `Invoice`, `Subscription`, `Project`, `Document`. The models that routinely appear in domain-failure reports.
4. **Secondary entities** — only if they regularly end up in log context.

Leave value objects, pivots, and read-only lookup models alone.

### Full pattern

```php
use Skeylup\OwlogsAgent\Contracts\HasLogContext;

class Order extends Model implements HasLogContext
{
    public function toLogContext(): array
    {
        return [
            'id'            => $this->getKey(),
            'reference'     => $this->reference,
            'status'        => $this->status,
            'total_cents'   => $this->total_cents,
            'currency'      => $this->currency,
            'customer_id'   => $this->customer_id,
            'team_id'       => $this->team_id,        // flatten relation
            'team_name'     => $this->team?->name,    //   to scalars
            'placed_at'     => $this->placed_at?->toIso8601String(),
        ];
    }

    public function getLogContextLabel(): string
    {
        return "Order #{$this->reference}";
    }
}
```

Call site stays ergonomic:
```php
Log::info('Order refunded', [
    'order'        => $order,   // → extra.order = {id, reference, status, total_cents, ...}
    'refund_cents' => $amount,
    'reason'       => $reason,
]);
```

### Pre-commit checklist for every `HasLogContext` implementation

- [ ] You'd be comfortable pasting the output in a shared Slack channel.
- [ ] No raw relations in the returned array — relations flattened to scalars.
- [ ] Dates as ISO strings, not raw Carbon instances.
- [ ] Zero secrets / tokens / hashed passwords / 2FA seeds.
- [ ] PII minimised to what the logs legitimately need.
- [ ] Return shape is **stable across records** (same keys for every instance) — enables server-side filtering.

## Multi-tenant context

This project runs on a `central` connection with tenant-scoped DBs (stancl/tenancy). The package auto-captures `auth()->user()` but **not** `tenant()` — and the same primary-key id can belong to different rows across tenants.

Pass tenant context explicitly when the log is tenant-scoped:

```php
Log::info('Site published', [
    'site' => $site,
    'tenant_id' => tenant('id'),
    'tenant_domain' => tenant('domain'),
]);
```

If a model lives on a tenant DB, include `tenant_id` in its `toLogContext()`:

```php
public function toLogContext(): array
{
    return [
        'id' => $this->getKey(),
        'tenant_id' => tenant('id'),  // disambiguator across tenants
        'slug' => $this->slug,
        // ...
    ];
}
```

For central-scope actions (signup, central User billing) tenant context is N/A — don't fabricate `tenant_id => null`, just omit it.

## Per-layer rules — where the log actually goes

A request usually crosses multiple layers (Controller → Action → Service → external call). Log at the **highest meaningful boundary** — the layer that answers *"what did the user/system intend?"*, not at every step. If an intermediate step genuinely matters for post-mortem, emit a dedicated `Log::info('[step.name]', [...])` — same `trace_id`, same chronological story.

### Livewire components

Livewire bundles every state change into a single endpoint (`/livewire/update`). The auto-captured route / URL tells you **nothing** about what the user did — which component, which action, which field, which record. You must compensate at the call site.

#### What counts as an action

| Hook | Log? | Notes |
|---|---|---|
| `wire:click` / `wire:submit` action method | ✅ | One log after the work succeeds — pass the model, the changes, the intent. |
| `updated*` lifecycle (`updatedFooBar()`) | ⚠️ | Only if it triggers a real side effect (DB write, external call). Plain property update → skip. |
| `wire:model.live` (every keystroke) | ❌ | Log the commit (save / blur / submit), never the typing. |
| `mount()` / `render()` / computed properties | ❌ | They re-fire on every poll, every refresh. Never. |
| `dehydrate()` / `hydrate()` | ❌ | Framework internals. |
| `placeholder()` / lazy island load | ❌ | Rendering. |

#### The base log every Livewire action needs

Always answer: **who did what, on which record, with what change?** The package already supplies the *who* (`auth()->user()` via `user_context`). You must supply the rest.

```php
public function save(): void
{
    $this->validate();
    $this->order->update($this->only(['status', 'shipping_method']));

    Log::info('Order updated by user', [
        'component' => static::class,
        'action'    => 'save',
        'order'     => $this->order,                // HasLogContext → enriched
        'changes'   => $this->order->getChanges(),  // {status: ['pending','paid'], ...}
    ]);
}
```

- `static::class` is critical — Livewire's URL hides which component fired.
- `getChanges()` is the gold standard for "what's different" — Eloquent already computed it.
- Don't pass `['user' => auth()->user()]` — already auto-attached.

#### Real-time inputs (`wire:model.live`)

Don't log the input update — log the commit:

```php
public function updatedQuantity(int $value): void
{
    if ($value < 1) {
        $this->addError('quantity', '...');  // UX only, no log
    }
}

public function addToCart(): void
{
    $this->cart->add($this->product, $this->quantity);
    Log::info('Item added to cart', [
        'cart'       => $this->cart,
        'product_id' => $this->product->id,
        'quantity'   => $this->quantity,
    ]);
}
```

#### Validation failures

Livewire validation surfaces inline errors — the user sees them. Usually no log. Exceptions:
- Same user trips the same rule repeatedly within minutes → `Log::warning` (possible probing).
- Validation guards a state machine ("cannot publish without payment method") → `Log::info` with the rejected fields — the *attempt* is itself a business signal.

#### Polling / `wire:poll`

`wire:poll` re-fires `render()` on a timer. Anything inside `render()` or computed properties will spam. If the polled action triggers a real side-effect refresh, gate it: only log when state actually changed (`$this->order->wasChanged()`).

### GraphQL operations (Lighthouse)

Lighthouse routes **every** GraphQL operation through one endpoint (`POST /graphql`). Like Livewire, the auto-captured route / URL tells you nothing about what ran — `createReport` and `deleteUser` look identical. The agent compensates automatically: when `nuwave/lighthouse` is installed it listens on `StartExecution` and rewrites the URI to `POST /graphql — mutation createReport` (operation type + client name, falling back to the root field names for anonymous operations), and stashes the breakdown under `extra.graphql_operations`. No call-site work is needed for grouping — but you still log the *business action* inside resolvers.

#### What counts as an action

| Site | Log? | Notes |
|---|---|---|
| Mutation resolver (state change) | ✅ | One log after the work succeeds — pass the model, the changes, the intent. Same rules as a controller action. |
| Query resolver | ❌ | A read. The rewritten URI already tells you it ran; don't log the fetch. |
| Field resolver (per-field) | ❌ | Fires once per field per row — N+1 of log lines. Never. |
| Introspection (`__schema` / `__type`) | ❌ | IDE plumbing — already skipped by the hook when `OWLOGS_GRAPHQL_IGNORE_INTROSPECTION=true`. |

#### Cardinality rule

The rewritten URI is a **grouping key** — it must stay low-cardinality. The hook deliberately keeps only the operation type, the client operation name, and root field names. **Never** put GraphQL variables (`id`, `email`, field values) into the URI or any grouping field; log them in the message body instead. Putting a variable in the URI would explode the bucket count and defeat the grouping.

```php
public function createReport($root, array $args): Report
{
    $report = Report::create($args['input']);

    Log::info('Report created via GraphQL', [
        'operation' => 'createReport',
        'report'    => $report,                 // HasLogContext → enriched
        'changes'   => $report->getChanges(),
    ]);

    return $report;
}
```

#### Batching

A single HTTP request can carry several operations. The URI keeps the **first** operation (stable key); `extra.graphql_operations` accumulates every operation (type, name, root fields) for the detail view.

### Controllers (classic HTTP)

Route info is auto-captured — keep call-site logs focused on outcome:
- `index` / `show` → never log (reads).
- `store` / `update` / `destroy` → one outcome log with the affected model + changes.
- Custom verbs (`/orders/{order}/refund`) → log the action, the actor (auto), the reason.

### Actions / Services / Use Cases

Single-responsibility classes are the natural place for **one outcome log per public method**. The class name *is* the action name — leverage it.

```php
final class RefundOrderAction
{
    public function handle(Order $order, int $cents, string $reason): Refund
    {
        $refund = Measure::track(
            'stripe.refund',
            fn () => $this->stripe->refunds->create([/* ... */]),
            ['cents' => $cents],
        );
        $order->update(['status' => 'refunded']);

        Log::info('Order refunded', [
            'order'     => $order,
            'refund_id' => $refund->id,
            'cents'     => $cents,
            'reason'    => $reason,
        ]);

        return $refund;
    }
}
```

Rules:
- Private helpers (`buildPayload()`, `validateCurrency()`) — never log. Implementation detail.
- When a service is called from multiple layers (controller, console, job), the **service** is the right place to log — not the caller — so the log is consistent across entry points.
- One outcome log per public method. If the method has multiple meaningful outcomes (created vs already-existed), one log per branch, not one generic "service finished".

### Class inheritance (abstract / template methods)

When a base class has a template method that subclasses extend:

```php
abstract class BaseImporter
{
    public function import(string $path): ImportResult
    {
        $result = Measure::track(
            'import.run',
            fn () => $this->run($path),
            ['driver' => static::class],
        );

        Log::info('Import finished', [
            'driver'      => static::class,
            'rows_ok'     => $result->ok,
            'rows_failed' => $result->failed,
            'duration_ms' => $result->durationMs,
        ]);

        return $result;
    }

    abstract protected function run(string $path): ImportResult;
}
```

- Always use `static::class` (late static binding) — `self::class` logs the abstract parent name and loses which concrete subclass ran.
- One log in the parent template means every subclass gets it for free — don't re-log inside `run()` unless the subclass adds a domain-specific outcome (e.g. specific failure modes for CSV vs XLSX).
- If subclasses override fully (no template method), each subclass adds its own log — no DRY shortcut.

### Jobs

The package auto-captures dispatch, start, finish, fail (queue / connection / attempt / job class). Your job is to log the **business outcome** of `handle()` and the **terminal failure** in `failed()`.

```php
public function handle(): void
{
    $report = Measure::track(
        'report.generate',
        fn () => $this->builder->build($this->periodId),
        ['period' => $this->periodId],
    );

    Log::info('Monthly report generated', [
        'period' => $this->periodId,
        'rows'   => $report->rowCount(),
        'bytes'  => $report->size(),
    ]);
}

public function failed(\Throwable $e): void
{
    Log::error('Monthly report failed terminally', [
        'period'    => $this->periodId,
        'attempts'  => $this->attempts(),
        'exception' => $e,
    ]);
}
```

- Don't log each retry attempt — the package already emits one row per attempt.
- For job **chains**: log per-job outcome (each job has its own row, linked by `trace_id`).
- For job **batches** (`Bus::batch(...)`): log inside `then()` / `catch()` / `finally()` (one log per batch outcome), not inside each chained job.
- Delayed dispatch — log the *intent* with the delay reason, otherwise the dispatch looks unmotivated:
  ```php
  ProcessExportJob::dispatch($export)->delay(now()->addHours(1));
  Log::info('Export scheduled for delayed run', [
      'export'  => $export,
      'runs_at' => now()->addHours(1)->toIso8601String(),
      'reason'  => 'rate-limit cooldown',
  ]);
  ```
- Owlogs-pipeline jobs (anything wrapped in `RemoteHandler::suppressedWhile()` per project convention) must not produce business `Log::info` lines — they're suppressed by design. Use `Measure::checkpoint` for observability instead.

### Scheduled tasks (`routes/console.php` / `Kernel::schedule()`)

Auto-logging covers **failures only** when `OWLOGS_AUTO_SCHEDULE=true`. For tasks that matter, add explicit start/outcome inside the command's `handle()`:

```php
public function handle(): int
{
    $count = Measure::track('billing.daily_run', fn () => $this->run());

    Log::info('Daily billing cycle complete', [
        'processed' => $count,
        'date'      => today()->toDateString(),
    ]);

    return self::SUCCESS;
}
```

Reasonable defaults:
- Cron that does nothing 99% of the time → no log on the empty pass; one log when it actually fires.
- Cron with side effects → one log per run with counts.
- Long-running cron (>30s) → `Measure::track` each phase, not just the global outcome.

### Listeners and Observers

Event dispatch is auto-logged when `OWLOGS_AUTO_EVENTS=true`. Log inside the **listener / observer** when the side effect itself is the business action:

```php
class SendOrderConfirmation
{
    public function handle(OrderPlaced $event): void
    {
        Mail::to($event->order->customer)->send(new OrderConfirmation($event->order));
        Log::info('Order confirmation sent', [
            'order'   => $event->order,
            'channel' => 'mail',
        ]);
    }
}
```

For Observers:
- `created` / `updated` / `deleted` — only log if `OWLOGS_AUTO_MODEL_CHANGES` is **not** covering this model. Otherwise duplicate.
- `creating` / `updating` / `deleting` — never (pre-events, may be rolled back).
- `forceDeleted` (hard delete) — always log regardless of `auto_log` config; it's irreversible.

### Mailables / Notifications

Mail send / notification dispatch is auto-logged. Don't log inside `build()` / `via()` / `toMail()` — that's rendering. Log at the **decision to send**, which is usually upstream (listener, service, controller).

### Middleware

Middleware is framework plumbing — don't log per-request "passed middleware X". Exceptions:
- Rate-limit hit: `Log::warning('Rate limit hit', ['key' => $key, 'route' => $route])` (custom rate limiters aren't covered by auth auto-events).
- IP block / geofence: `Log::warning('Request blocked', ['ip' => $ip, 'reason' => 'geo'])`.
- Custom auth guard rejection that doesn't reach Fortify's auto-events.

### Custom validation rules

No log inside the rule. Validation outcome is handled at the Form Request / Livewire action level (see Livewire validation guidance above).

### Webhooks and idempotency

Always capture the provider's event / idempotency ID — it's how you correlate retries and cross-reference the provider's dashboard.

```php
public function handle(StripeEvent $event): Response
{
    Log::info('Webhook received', [
        'provider'  => 'stripe',
        'type'      => $event->type,
        'event_id'  => $event->id,   // dedup key
        'livemode'  => $event->livemode,
    ]);
    // ...
}
```

If you detect a duplicate (already processed):

```php
Log::debug('Webhook ignored (duplicate)', ['provider' => 'stripe', 'event_id' => $event->id]);
```

For outbound webhooks **you** send: log dispatch, log delivery success / failure with destination + status code + body size.

### Admin actions and impersonation

Low-volume, high-value. Always log:
- Role / permission grant or revoke.
- Plan change initiated by an admin (not self-serve).
- Feature flag toggle, system setting change, quota override.
- Account suspension, ban, force-delete of a user-facing resource → `Log::warning`.
- Data export on someone else's behalf → log who-for-whom + scope.

**Impersonation** needs two boundary logs because, during the impersonated session, `auth()->user()` is the impersonated user — the package will tag every log with **them**, not the admin. These two boundary logs are the only way an auditor reconstructs the session:

```php
Log::info('Impersonation started', [
    'impersonator' => $admin,            // both must implement HasLogContext
    'impersonated' => $target,
    'reason'       => $request->input('reason'),
]);

// later, when stopping
Log::info('Impersonation stopped', [
    'impersonator'     => $admin,
    'impersonated'     => $target,
    'duration_seconds' => $seconds,
]);
```

### Bulk operations

One log for the batch — **never** one per row. Capture count, criteria, outcome.

```php
$count = User::where('plan', 'free')
    ->where('last_seen_at', '<', now()->subYear())
    ->update(['status' => 'dormant']);

Log::info('Dormant users marked', [
    'count'    => $count,
    'criteria' => ['plan' => 'free', 'inactive_since_months' => 12],
]);
```

If individual items can fail mid-batch:

```php
$results = collect($items)->map(fn ($i) => $this->process($i));

Log::info('Batch processed', [
    'total'      => $results->count(),
    'ok'         => $results->where('ok')->count(),
    'failed_ids' => $results->where('ok', false)->pluck('id')->all(),
]);
```

If `failed_ids` could be huge (>50), log a count and emit one `Log::error` per failure **type**, not per ID.

### File operations (import / export / upload / download)

Treat as a business action. Log row counts, bytes, the resource the file relates to.

```php
$path = Measure::track(
    'export.invoices',
    fn () => $this->exporter->build($criteria),
    ['format' => 'csv'],
);

Log::info('Invoice export generated', [
    'criteria'   => $criteria,
    'rows'       => $this->exporter->rowCount(),
    'bytes'      => Storage::disk('s3')->size($path),
    'path'       => $path,
    'expires_at' => now()->addHours(24)->toIso8601String(),
]);
```

- Imports: optionally emit `Log::info('import.started', [...])` if the start moment matters, then one outcome log with success / failure counts. Never one log per row.
- User uploads: log filename + size + sha256, **never** the file contents.
- Downloads: log only if the file is sensitive (export of personal data, invoice PDF) — not on every static asset.

### Tests

Mocked I/O (Mockery, `Http::fake()`) and factory data — fine to leave logging on; the `testing` log channel doesn't ship externally. Specifically for this project: real LLM / Stripe / external API calls **must** be mocked in tests (no live tokens in CI), so the resulting logs are safe — there's no token expense or rate-limit risk. Don't disable logging in tests: their absence makes a failing test harder to diagnose.

## Execution plan (run in order)

> Use this as the macro plan. For per-layer contracts (Livewire, Services, Jobs, Schedule, Listeners, Webhooks, Admin/Impersonation, Bulk, Files), follow the **Per-layer rules** section above.

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

Follow the dedicated **HasLogContext rules** section above. Priority order: `User` → tenant/team → main transactional entities. Minimum for the User model:

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

Most categories are on by default — no `.env` changes needed for jobs, auth, mail, notifications, slow queries, cache, HTTP client errors, model changes, app events.

Opt-in extras (disabled by default):
```env
OWLOGS_AUTO_MIGRATION=true   # migrations — noisy on deploys, enable if you audit them
OWLOGS_AUTO_SCHEDULE=true    # scheduled task failures
```

`OWLOGS_AUTO_MODEL_CHANGES` is already `true`, but **you must still populate** `auto_log.model_changes_models` in `config/owlogs.php` — restrict to domain-critical models (Subscription, Order, Invoice). Never leave it unscoped for all models.
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
