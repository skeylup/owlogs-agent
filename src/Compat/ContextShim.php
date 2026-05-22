<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Compat;

use Illuminate\Support\Facades\Context;

/**
 * Thin compatibility wrapper around Laravel 11's `Illuminate\Support\Facades\Context`.
 *
 * Laravel 11+ shipped the Context facade for cross-job/request data
 * propagation. On Laravel 9 and 10 the facade does not exist, so calling
 * `Context::addHidden(...)` blows up at boot. This shim:
 *
 *   - Delegates every call to the real Context facade when it is available.
 *   - Falls back to a process-local static store otherwise.
 *
 * The polyfill is intentionally minimal: it does not propagate hidden values
 * across queue job boundaries (Laravel's Context does that via job payloads).
 * Synchronous workflows on L9/L10 keep their context intact within a request
 * or CLI invocation, which is good enough for most observability use cases.
 */
final class ContextShim
{
    /** @var array<string, mixed> */
    private static array $store = [];

    private static ?bool $available = null;

    /**
     * True when Laravel's Context facade is available (Laravel 11+).
     */
    public static function available(): bool
    {
        if (self::$available === null) {
            self::$available = class_exists(Context::class);
        }

        return self::$available;
    }

    public static function addHidden(string $key, mixed $value): void
    {
        if (self::available()) {
            Context::addHidden($key, $value);

            return;
        }

        self::$store[$key] = $value;
    }

    public static function addHiddenIf(string $key, mixed $value): void
    {
        if (self::available()) {
            Context::addHiddenIf($key, $value);

            return;
        }

        if (! array_key_exists($key, self::$store)) {
            self::$store[$key] = $value;
        }
    }

    public static function pushHidden(string $key, mixed $value): void
    {
        if (self::available()) {
            Context::pushHidden($key, $value);

            return;
        }

        $existing = self::$store[$key] ?? [];
        if (! is_array($existing)) {
            $existing = [$existing];
        }
        $existing[] = $value;
        self::$store[$key] = $existing;
    }

    /**
     * @template TDefault
     *
     * @param  TDefault  $default
     * @return mixed|TDefault
     */
    public static function getHidden(string $key, mixed $default = null): mixed
    {
        if (self::available()) {
            return Context::getHidden($key, $default);
        }

        return self::$store[$key] ?? $default;
    }

    public static function hasHidden(string $key): bool
    {
        if (self::available()) {
            return Context::hasHidden($key);
        }

        return array_key_exists($key, self::$store);
    }

    public static function forgetHidden(string $key): void
    {
        if (self::available()) {
            Context::forgetHidden($key);

            return;
        }

        unset(self::$store[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function allHidden(): array
    {
        if (self::available()) {
            return Context::allHidden();
        }

        return self::$store;
    }

    /**
     * Register a callback to run when Laravel hydrates Context from a queue
     * job payload. No-op on L9/L10 — without Laravel's Context, hydration
     * does not happen and queue-side enrichment must fall back to event
     * listeners (Queue::before / JobProcessing) that we register elsewhere.
     */
    public static function hydrated(callable $callback): void
    {
        if (self::available()) {
            Context::hydrated($callback);
        }
        // Polyfill no-op: see the docblock.
    }

    /**
     * Test/CLI helper — wipe the polyfill store between requests when the
     * real Context facade is not handling the lifecycle for us. Idempotent.
     */
    public static function flushPolyfill(): void
    {
        self::$store = [];
    }
}
