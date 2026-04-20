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
        'origin' => true,
        'app_name' => true,
        'app_env' => true,
        'app_url' => true,
        'uri' => true,
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
    | URI Resolver
    |--------------------------------------------------------------------------
    |
    | Optional callback to enrich the URI context for specific request types
    | (e.g. Livewire components, SPA routes).
    |
    */

    'uri_resolver' => null,

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
    | Auto-Logging
    |--------------------------------------------------------------------------
    */

    'auto_log' => [
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

        // Cache
        'cache_miss' => env('OWLOGS_AUTO_CACHE_MISS', false),
        'cache_hit' => env('OWLOGS_AUTO_CACHE_HIT', false),

        // HTTP Client (outgoing)
        'http_client' => env('OWLOGS_AUTO_HTTP_CLIENT', true),

        // Scheduler
        'schedule' => env('OWLOGS_AUTO_SCHEDULE', false),

        // Model changes
        'model_changes' => env('OWLOGS_AUTO_MODEL_CHANGES', true),
        'model_changes_models' => null,

        // Event dispatch
        'event_dispatch' => env('OWLOGS_AUTO_EVENT_DISPATCH', true),
    ],

];
