<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Enable Owlogs Agent
    |--------------------------------------------------------------------------
    */

    'enabled' => env('OWLOGS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Workspace API key
    |--------------------------------------------------------------------------
    |
    | Grab it from your workspace's API keys page on https://www.owlogs.com.
    | Sent in the X-Api-Key header on every ingestion request.
    |
    */

    'api_key' => env('OWLOGS_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Auto-register on log stack
    |--------------------------------------------------------------------------
    |
    | When true, the service provider defines the `owlogs` channel at boot
    | (if not already declared in config/logging.php) and appends it to the
    | `stack` channel — so any `Log::*()` call is forwarded to Owlogs without
    | the user touching LOG_STACK or config/logging.php.
    |
    | Set to false to wire it manually (e.g. add `owlogs` to LOG_STACK, or
    | declare a custom channel definition in config/logging.php).
    |
    */

    'auto_register_stack' => env('OWLOGS_AUTO_REGISTER_STACK', true),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Two-stage ship pipeline:
    |
    |  [RAM buffer in RemoteHandler] → flush → [cross-process LogBufferStore]
    |        → debounced dispatch → [ShipBufferedLogsJob] → HTTPS POST
    |
    | Stage 1 — when RAM records leave the current PHP process:
    |
    |  - Non-Octane (PHP-FPM / Herd / Valet, artisan one-shot, queue:work):
    |    records accumulate during the request/job/command and flush exactly
    |    once at the boundary (terminating hook, Queue::after,
    |    CommandFinished).
    |  - Octane (Swoole / RoadRunner / FrankenPHP, long-lived workers):
    |    records batch across requests in the same worker. A flush fires
    |    when either `octane.batch_count` records are buffered OR
    |    `octane.window_ms` ms have elapsed since the first buffered record.
    |    Under Swoole a 1s tick enforces the window during idle periods.
    |
    | Stage 2 — when the store's accumulated rows are shipped to Owlogs:
    |
    |  - Every flush APPENDs its rows to `buffer_store` (Redis list or
    |    JSONL file) and tries to dispatch ONE ShipBufferedLogsJob with
    |    `ship.debounce_ms` delay. A Cache::add marker keeps the window
    |    single-job: N flushes in the debounce window → 1 queued ship job.
    |  - ShipBufferedLogsJob drains up to `ship.batch_count` rows, splits
    |    the payload by `max_payload_bytes` and POSTs each chunk. While
    |    the store still has pending rows at the end of handle(), it
    |    self-re-dispatches without delay.
    |
    | Legacy caps (still honored):
    |
    |  - `batch_size * 2` / `max_payload_bytes * 2` act as RAM hard
    |    ceilings in RemoteHandler::write() to protect against runaway
    |    log loops during a single request.
    |  - `max_payload_bytes` drives the HTTP chunker inside the ship job.
    |
    | `flush_strategy` overrides runtime detection (testing / debugging):
    | null → auto, 'octane' → OctaneWindowPolicy, 'end_of_request' →
    | EndOfRequestPolicy.
    |
    | `buffer_store` picks the cross-process buffer:
    |  - 'redis' (default): Redis list, atomic Lua-scripted drain. Best
    |    performance, requires a `redis_connection` (default 'default').
    |  - 'file': JSONL file with advisory flock(). Zero dependencies,
    |    filesystem must be writable. Suitable for Herd / single-server
    |    setups without Redis.
    |  - 'memory': process-local, testing only — not shared with the
    |    queue worker running ShipBufferedLogsJob.
    |
    */

    'transport' => [
        'ingest_url' => env('OWLOGS_INGEST_URL'),
        'batch_size' => env('OWLOGS_BATCH_SIZE', 50),
        'max_payload_bytes' => env('OWLOGS_MAX_PAYLOAD_BYTES', 512 * 1024),
        'min_flush_interval_ms' => env('OWLOGS_MIN_FLUSH_INTERVAL_MS', 500),
        'compression' => env('OWLOGS_COMPRESSION', true),
        'queue' => env('OWLOGS_QUEUE', 'default'),
        'connection' => env('OWLOGS_QUEUE_CONNECTION'),
        'timeout_s' => env('OWLOGS_TIMEOUT', 30),

        'buffer_store' => env('OWLOGS_BUFFER_STORE', 'redis'),
        'redis_connection' => env('OWLOGS_BUFFER_REDIS_CONNECTION', 'default'),
        'redis_key' => env('OWLOGS_BUFFER_REDIS_KEY', 'owlogs:buffer'),
        'file_path' => env('OWLOGS_BUFFER_FILE_PATH'),

        'ship' => [
            'debounce_ms' => env('OWLOGS_SHIP_DEBOUNCE_MS', 10000),
            'batch_count' => env('OWLOGS_SHIP_BATCH_COUNT', 256),
        ],

        /*
        |----------------------------------------------------------------
        | Buffer limits
        |----------------------------------------------------------------
        |
        | Two guards that protect both ends of the pipeline when shipments
        | stall:
        |
        |  - max_rows: hard cap on the cross-process buffer (Redis list /
        |    JSONL file / in-memory array). When append() would push the
        |    row count above the cap, the OLDEST rows are dropped FIFO so
        |    the client's storage can never grow unbounded during an
        |    outage or a tripped circuit. Set to 0 to disable the cap
        |    (legacy unbounded behaviour).
        |
        |  - max_age_s: drain-time TTL for NOMINAL operation. Rows whose
        |    `logged_at` is older than this many seconds are filtered out
        |    before the HTTP POST. DISABLED by default (0), and that is the
        |    right default: the store→ship latency in healthy operation is
        |    already `ship.debounce_ms` + queue wait + up to the retry
        |    backoff (120s), so any small positive value silently guillotines
        |    the oldest — and usually the MAJORITY — of every drain the moment
        |    the ship queue is even slightly behind. Storage is bounded by the
        |    FIFO `max_rows` cap; the post-outage burst is bounded by
        |    `retry_max_age_s`. Leave at 0 unless you deliberately want a hard
        |    nominal age cutoff AND have sized it well above your worst-case
        |    ship latency.
        |
        |  - retry_max_age_s: drain-time TTL applied INSTEAD of max_age_s
        |    while inside an outage/recovery window (circuit tripped, or
        |    its cooldown recently elapsed). This is what bounds a stale
        |    backlog dump after a real outage — so max_age_s does not need
        |    to (and must not) do that job in the nominal path. Rows retained
        |    through a 429 / outage ship on recovery within this wider window
        |    (default 600s) instead of being wiped as stale.
        |
        */

        'buffer' => [
            'max_rows' => env('OWLOGS_BUFFER_MAX_ROWS', 5000),
            // Default 0 (disabled). See the note above: a positive nominal
            // age cutoff drops late-but-valid rows under any ship-queue lag.
            'max_age_s' => env('OWLOGS_BUFFER_MAX_AGE_S', 0),
            'retry_max_age_s' => env('OWLOGS_BUFFER_RETRY_MAX_AGE_S', 600),
        ],

        /*
        |----------------------------------------------------------------
        | Ingest circuit breaker
        |----------------------------------------------------------------
        |
        | The ship job trips a Cache-based breaker when the server returns
        | a fatal status (403 = no subscription, 429 = quota exhausted).
        | While the breaker is tripped:
        |
        |  - RemoteHandler::write() drops new records BELOW
        |    `retain_min_level` (default error); error/critical rows keep
        |    buffering within the store's FIFO `max_rows` cap so the
        |    high-signal slice survives the outage (the server keeps
        |    accepting it on a quota grace budget).
        |  - flush() persists only the retained-severity slice to the store.
        |  - ShipBufferedLogsJob exits early but KEEPS the spool; on 429 the
        |    failed chunk is requeued instead of wiped.
        |
        | Every record dropped during the cooldown is tallied; the first
        | ship after recovery emits ONE synthetic WARNING row with the drop
        | counts so the gap is visible server-side.
        |
        | The breaker auto-rearms after `cooldown_s` — a tenant who
        | upgrades / pays for more quota recovers on their own without
        | any manual intervention.
        |
        */

        'circuit' => [
            'enabled' => env('OWLOGS_CIRCUIT_ENABLED', true),
            'cooldown_s' => env('OWLOGS_CIRCUIT_COOLDOWN_S', 300),
            'retain_min_level' => env('OWLOGS_CIRCUIT_RETAIN_MIN_LEVEL', 'error'),
        ],

        'octane' => [
            'window_ms' => env('OWLOGS_OCTANE_WINDOW_MS', 2000),
            'batch_count' => env('OWLOGS_OCTANE_BATCH_COUNT', 20),
        ],

        'flush_strategy' => env('OWLOGS_FLUSH_STRATEGY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Context Fields
    |--------------------------------------------------------------------------
    */

    'fields' => [
        'trace_id' => true,
        'span_id' => true,
        'parent_span_id' => true,
        'origin' => true,
        'app_name' => true,
        'app_env' => true,
        'app_url' => true,
        'uri' => true,
        'http_method' => true,
        'route_name' => true,
        'route_action' => true,
        'ip' => true,
        'user_agent' => true,
        'user_id' => true,
        'duration_ms' => true,
        'git_sha' => true,
        'request_input' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Context
    |--------------------------------------------------------------------------
    */

    'queue' => [
        'enabled' => true,
        'fields' => [
            'job_class' => true,
            'job_attempt' => true,
            'queue_name' => true,
            'connection_name' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Artisan Command Context
    |--------------------------------------------------------------------------
    */

    'commands' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Performance Measurement (Measure)
    |--------------------------------------------------------------------------
    */

    'measure' => [
        'db_queries' => env('OWLOGS_MEASURE_DB', false),
        'memory' => env('OWLOGS_MEASURE_MEMORY', true),
        'n_plus_one_threshold' => env('OWLOGS_N_PLUS_ONE_THRESHOLD', 5),
        'n_plus_one_callback' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Caller Trace (file & line)
    |--------------------------------------------------------------------------
    */

    'caller' => [
        'enabled' => true,
        'max_frames' => 15,
        'ignore_paths' => [
            '/vendor/',
            '/packages/skeylup/owlogs-agent/src/',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | JSON Formatter
    |--------------------------------------------------------------------------
    */

    'json' => [
        'enabled' => env('OWLOGS_JSON', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Breadcrumbs
    |--------------------------------------------------------------------------
    */

    'breadcrumbs' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Livewire integration
    |--------------------------------------------------------------------------
    |
    | When `livewire/livewire` is installed, the agent registers a
    | ComponentHook that rewrites the URI of `/livewire/update` requests as
    | `POST /livewire — Component::method` and stashes the list of
    | invoked methods in `extra.livewire.calls`. No-op for projects without
    | Livewire (classic Laravel, Inertia, API-only).
    |
    */

    'livewire' => [
        'enabled' => env('OWLOGS_LIVEWIRE_HOOK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | GraphQL integration (Lighthouse)
    |--------------------------------------------------------------------------
    |
    | When `nuwave/lighthouse` is installed, the agent listens on
    | StartExecution and rewrites the URI of `/graphql` requests as
    | `POST /graphql — mutation createReport` (operation type + name, with a
    | fallback to the root field names) and stashes the operation breakdown
    | under `extra.graphql_operations`. No-op for projects without Lighthouse.
    |
    */

    'graphql' => [
        'enabled' => env('OWLOGS_GRAPHQL_HOOK', true),
        // Skip introspection queries (IDE schema fetches) — pure noise.
        'ignore_introspection' => env('OWLOGS_GRAPHQL_IGNORE_INTROSPECTION', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | URI Resolver
    |--------------------------------------------------------------------------
    |
    | Optional callback that can rewrite the URI captured for specific request
    | types (custom SPA routes, Inertia, …) so the Owlogs UI can group by
    | feature rather than by opaque endpoint. Livewire is handled natively by
    | the integration above and does not need this hook.
    |
    | Receives `($request, array $fields)`. Use `Context::addHidden('uri', …)`
    | inside the callback to override the captured URI.
    |
    */

    'uri_resolver' => env('OWLOGS_URI_RESOLVER') ?: null,

    /*
    |--------------------------------------------------------------------------
    | Ignored URIs
    |--------------------------------------------------------------------------
    |
    | HTTP request paths whose logs must never be forwarded to Owlogs. Matched
    | with `Str::is`, so wildcards (`*`) are supported.
    |
    | Laravel's built-in `broadcasting/auth` endpoint fires on every websocket
    | handshake and creates a lot of low-signal noise, so it is ignored by
    | default. Set `OWLOGS_IGNORE_BROADCASTING=false` to forward it again.
    |
    */

    'ignored_uris' => array_values(array_filter([
        env('OWLOGS_IGNORE_BROADCASTING', true) ? 'broadcasting/auth' : null,
    ])),

    /*
    |--------------------------------------------------------------------------
    | Ignored Events
    |--------------------------------------------------------------------------
    |
    | Event class names whose `event.dispatched: ...` auto-log must never be
    | forwarded to Owlogs. Matched with `Str::is`, so wildcards (`*`) are
    | supported — useful to silence a whole package namespace.
    |
    | Examples:
    |
    |   'ignored_events' => [
    |       \Spatie\LaravelSettings\Events\SettingsLoaded::class,
    |       'Spatie\\LaravelSettings\\Events\\*',
    |       'App\\Events\\Internal\\*',
    |   ],
    |
    | Only impacts the `auto_log.event_dispatch` listener. Explicit
    | `Log::info('event.dispatched: ...')` calls are not filtered here.
    |
    */

    'ignored_events' => [],

    /*
    |--------------------------------------------------------------------------
    | Ignored Jobs
    |--------------------------------------------------------------------------
    |
    | Class-name prefixes whose job lifecycle auto-logs (`job.dispatched`,
    | `job.started`, `job.completed`, `job.failed`, `job.retrying`) must never
    | be forwarded to Owlogs. Matched with `str_starts_with`, so a trailing
    | namespace separator silences a whole package.
    |
    | The agent already treats its own jobs and the framework queue plumbing
    | (Illuminate\Queue\, Illuminate\Events\, Laravel\Scout\, Laravel\Telescope\,
    | ...) as internal; this list lets you extend that set for third-party
    | infrastructure jobs that would otherwise pollute business traces.
    |
    | Example:
    |
    |   'ignored_jobs' => [
    |       'Spatie\\Backup\\',
    |       'App\\Jobs\\Internal\\',
    |   ],
    |
    */

    'ignored_jobs' => [],

    /*
    |--------------------------------------------------------------------------
    | Redaction
    |--------------------------------------------------------------------------
    |
    | Central PII scrubbing for everything the agent captures: request input,
    | log context/extra, Livewire call params and model-change attributes.
    | Publish this config and extend the lists here — never edit vendor files.
    |
    |  - `key_patterns`: case-insensitive substrings matched against array
    |    keys (request fields, context keys, model attribute names, ...).
    |    A matching key has its whole value — nested arrays included —
    |    replaced by `mask`. Defaults are the union of the lists that used
    |    to be hardcoded in AddLogContext, AutoLogger and OwlogsLivewireHook.
    |
    |  - `value_regexes`: PCRE patterns applied to every captured string
    |    VALUE regardless of its key; each match is replaced by `mask`.
    |    Useful for secrets hiding inside free text, e.g. '/\b\d{16}\b/'
    |    for card numbers. Empty by default.
    |
    */

    'redaction' => [
        'key_patterns' => [
            'password',
            'secret',
            'token',
            'key',
            'authorization',
            'cookie',
            'credit_card',
            'two_factor',
        ],
        'value_regexes' => [],
        'mask' => '********',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | Volume dials enforced in the Monolog handler BEFORE buffering — a
    | sampled-out record never reaches the buffer, the store or the wire.
    |
    |  - `levels`: per-level keep probability between 0.0 (drop everything)
    |    and 1.0 (keep everything, the default). Lets busy apps keep a slice
    |    of debug/info noise while retaining every warning and error.
    |
    |  - `traces`: per-URI-pattern TRACE sampling ('pattern' => rate).
    |    Patterns match the request path with Str::is wildcards (same
    |    convention as `ignored_uris`); the first match wins. The decision
    |    is derived deterministically from the trace_id, so a kept trace
    |    keeps ALL its rows (queue jobs included) and a dropped trace
    |    disappears entirely — never half a trace.
    |
    |      'traces' => [
    |          'api/polling/*' => 0.05,
    |          'up' => 0.0,
    |      ],
    |
    */

    'sampling' => [
        'levels' => [
            'debug' => env('OWLOGS_SAMPLE_DEBUG', 1.0),
            'info' => env('OWLOGS_SAMPLE_INFO', 1.0),
            'notice' => env('OWLOGS_SAMPLE_NOTICE', 1.0),
            'warning' => env('OWLOGS_SAMPLE_WARNING', 1.0),
            'error' => env('OWLOGS_SAMPLE_ERROR', 1.0),
            'critical' => env('OWLOGS_SAMPLE_CRITICAL', 1.0),
            'alert' => env('OWLOGS_SAMPLE_ALERT', 1.0),
            'emergency' => env('OWLOGS_SAMPLE_EMERGENCY', 1.0),
        ],
        'traces' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Error-storm deduplication
    |--------------------------------------------------------------------------
    |
    | Two protections applied at buffer time — both preserve exact occurrence
    | statistics through the `count` / `suppressed_count` row fields, which
    | the server maps back into issue tallies:
    |
    |  - Window collapse (`enabled`): within one flush window, rows with the
    |    same fingerprint (level + exception class + caller file:line +
    |    message + user) merge into a single row carrying `count` plus
    |    `first_at` / `last_at` timestamps. Pure in-memory, zero I/O cost,
    |    applies to every level. State resets on each flush, so nothing
    |    leaks across requests under Octane.
    |
    |  - Per-fingerprint rate cap: fingerprints at or above `cap_min_level`
    |    are additionally metered across flush windows (Cache-backed, so the
    |    cap holds across FPM processes and Octane workers). Once a
    |    fingerprint has shipped `max_per_minute` rows within a minute,
    |    further occurrences are suppressed and only one sampled row per
    |    `sample_interval_s` goes out, carrying the accumulated
    |    `suppressed_count`. Levels below `cap_min_level` skip the Cache
    |    metering entirely to keep the happy path free of extra I/O.
    |
    */

    'dedup' => [
        'enabled' => env('OWLOGS_DEDUP_ENABLED', true),
        'cap_min_level' => env('OWLOGS_DEDUP_CAP_MIN_LEVEL', 'warning'),
        'max_per_minute' => env('OWLOGS_DEDUP_MAX_PER_MINUTE', 60),
        'sample_interval_s' => env('OWLOGS_DEDUP_SAMPLE_INTERVAL_S', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-Logging
    |--------------------------------------------------------------------------
    */

    'auto_log' => [
        // Routing
        'route_matched' => env('OWLOGS_AUTO_ROUTE_MATCHED', true),

        // Jobs / Queue
        'job_dispatched' => env('OWLOGS_AUTO_JOB_DISPATCHED', true),
        'job_started' => env('OWLOGS_AUTO_JOB_STARTED', true),
        'job_completed' => env('OWLOGS_AUTO_JOB_COMPLETED', true),
        'job_failed' => env('OWLOGS_AUTO_JOB_FAILED', true),
        'job_retrying' => env('OWLOGS_AUTO_JOB_RETRYING', true),

        // Auth
        'auth_login' => env('OWLOGS_AUTO_AUTH_LOGIN', true),
        'auth_failed' => env('OWLOGS_AUTO_AUTH_FAILED', true),
        'auth_logout' => env('OWLOGS_AUTO_AUTH_LOGOUT', true),
        'auth_password_reset' => env('OWLOGS_AUTO_AUTH_PASSWORD_RESET', true),
        'auth_verified' => env('OWLOGS_AUTO_AUTH_VERIFIED', true),

        // Mail / Notifications
        'mail_sent' => env('OWLOGS_AUTO_MAIL_SENT', true),
        'notification_sent' => env('OWLOGS_AUTO_NOTIFICATION_SENT', true),
        'notification_failed' => env('OWLOGS_AUTO_NOTIFICATION_FAILED', true),

        // Database
        'slow_query' => env('OWLOGS_AUTO_SLOW_QUERY', true),
        'slow_query_ms' => env('OWLOGS_AUTO_SLOW_QUERY_MS', 500),
        'migration' => env('OWLOGS_AUTO_MIGRATION', false),
        // DB transactions — off by default because transaction-heavy code
        // (Eloquent saves, batched inserts) produces a lot of low-value noise.
        // Flip on when debugging silent rollbacks.
        'db_transaction' => env('OWLOGS_AUTO_DB_TRANSACTION', false),

        // Cache
        'cache_miss' => env('OWLOGS_AUTO_CACHE_MISS', false),
        'cache_hit' => env('OWLOGS_AUTO_CACHE_HIT', false),

        // HTTP Client (outgoing)
        'http_client' => env('OWLOGS_AUTO_HTTP_CLIENT', true),

        // HTTP responses (incoming) — log responses the host app returns whose
        // status is >= http_response_min_status. Complements `http_client`,
        // which logs OUTGOING calls. Default min status 400 logs client/server
        // errors only; 3xx redirects are normal flow and stay out. Lower to 300
        // to also capture redirects.
        'http_response' => env('OWLOGS_AUTO_HTTP_RESPONSE', true),
        'http_response_min_status' => env('OWLOGS_AUTO_HTTP_RESPONSE_MIN_STATUS', 400),

        // Middleware / pipeline rejections — log framework-level refusals
        // (auth 401, authorization 403, validation 422, CSRF 419, abort()) that
        // Laravel keeps in `internalDontReport`, so they never reach the log
        // stack. Server errors (5xx) are left to Laravel's own exception report.
        'middleware_rejection' => env('OWLOGS_AUTO_MIDDLEWARE_REJECTION', true),

        // Scheduler
        'schedule' => env('OWLOGS_AUTO_SCHEDULE', false),

        // Livewire — emit a debug line per component method call. Context
        // enrichment (livewire_calls array under extra) happens regardless
        // when livewire/livewire is installed; this toggle adds the
        // standalone line so the timeline shows the call as a discrete row.
        'livewire_call' => env('OWLOGS_AUTO_LIVEWIRE_CALL', true),

        // GraphQL — emit a debug line per Lighthouse operation. Context
        // enrichment (graphql_operations array under extra) happens regardless
        // when nuwave/lighthouse is installed; this toggle adds the standalone
        // line so the timeline shows the operation as a discrete row.
        'graphql_operation' => env('OWLOGS_AUTO_GRAPHQL_OPERATION', true),

        // Model changes
        'model_changes' => env('OWLOGS_AUTO_MODEL_CHANGES', true),
        'model_changes_models' => null,

        // Event dispatch
        'event_dispatch' => env('OWLOGS_AUTO_EVENT_DISPATCH', true),
    ],

];
