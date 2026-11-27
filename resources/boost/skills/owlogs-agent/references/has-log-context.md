# HasLogContext rules — enriching log rows with model info

`HasLogContext` is the **only safe way** to attach rich domain-model information to a log row. Without it, models either leak everything (default Eloquent serialization) or show up as a useless `{_model, id}` stub.

### How the package consumes it (verified against source)

Two entry points in the package:

**A. Authenticated user — automatic on every HTTP request.**
In `src/Middleware/AddLogContext.php`, after the request is handled, if `$request->user() instanceof HasLogContext`:
```php
Context::add('user_context', $user->toLogContext());
Context::add('user_label', $user->getLogContextLabel());
```
→ lands in every log row as `extra.user` + `extra.user_label`. Zero call-site cost. A fallback in `src/Handlers/Concerns/BuffersAndShips.php` (`buildExtra()`) also resolves `auth()->user()` at log-write time for contexts where the middleware didn't run (queue, command).

**B. Explicit context — any key you pass to `Log::*()`.**
In `src/Handlers/Concerns/BuffersAndShips.php`, `transformContext()` walks the context array recursively:

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

## Multi-tenant apps (only if the app has tenants)

The package captures the authenticated user, but **no tenant**: it knows nothing of your tenancy setup. In a multi-tenant app the same primary-key id can belong to different rows in different tenants, so pass the tenant explicitly when the log is tenant-scoped. Use whatever your app exposes for the current tenant (`$tenantId` below):

```php
Log::info('Site published', [
    'site' => $site,
    'tenant_id' => $tenantId,
]);
```

If a model lives in a tenant-scoped database or table, include the tenant key in its `toLogContext()`:

```php
public function toLogContext(): array
{
    return [
        'id' => $this->getKey(),
        'tenant_id' => $this->tenant_id,  // or your tenancy package's current tenant: disambiguates ids across tenants
        'slug' => $this->slug,
        // ...
    ];
}
```

For actions outside any tenant (signup, global billing), omit the tenant key — don't fabricate `tenant_id => null`.
