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
    | batch_size:            how many buffered log rows trigger a flush.
    | max_payload_bytes:     soft cap on the JSON payload size; when exceeded,
    |                        a flush is triggered (and the batch is split into
    |                        chunks if a single flush would exceed it).
    | min_flush_interval_ms: minimum delay between two soft-triggered flushes
    |                        (batch_size / max_payload_bytes). Shutdown and
    |                        Queue::after always force a flush.
    | compression:           gzip the request body before sending.
    | queue:                 queue name for the SendLogsJob.
    | connection:            queue connection (null = default app connection).
    | timeout_s:             HTTP timeout when the job POSTs to the server.
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
        'db_transaction' => env('OWLOGS_AUTO_DB_TRANSACTION', true),
        'migration' => env('OWLOGS_AUTO_MIGRATION', false),

        // Cache
        'cache_miss' => env('OWLOGS_AUTO_CACHE_MISS', true),
        'cache_hit' => env('OWLOGS_AUTO_CACHE_HIT', true),

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
