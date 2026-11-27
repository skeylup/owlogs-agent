# Per-layer rules — where the log actually goes

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
- Owlogs-pipeline code (anything wrapped in `RemoteHandler::suppressedWhile()`) must not produce business `Log::info` lines — they're suppressed by design. Use `Measure::checkpoint` for observability instead.

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

Event dispatch is auto-logged when `OWLOGS_AUTO_EVENT_DISPATCH=true` (the default). Log inside the **listener / observer** when the side effect itself is the business action:

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
- Custom auth guard rejection that doesn't fire Laravel's auth events (so the `auth_*` auto-events miss it).

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

Mocked I/O (Mockery, `Http::fake()`) and factory data — fine to leave logging on; the `testing` log channel doesn't ship externally. Real external calls (LLM, payment, third-party APIs) should be mocked in tests anyway (no live tokens in CI), so the resulting logs are safe — no token expense or rate-limit risk. Don't disable logging in tests: their absence makes a failing test harder to diagnose.
