# Measure rules

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
