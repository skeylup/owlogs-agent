<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Transport;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Circuit breaker for the Owlogs ingest pipeline.
 *
 * Tripped when the server has rejected our shipments with a fatal status
 * (403 = no subscription, 429 = quota exhausted). While tripped:
 *
 *   - {@see RemoteHandler::write()} drops new records on the floor.
 *   - {@see RemoteHandler::flush()} discards the in-memory buffer without
 *     writing to the cross-process store.
 *   - {@see RemoteHandler::scheduleShip()} does not dispatch ship jobs.
 *   - {@see ShipBufferedLogsJob::handle()} clears the store and returns
 *     before doing any HTTP work.
 *
 * The breaker auto-rearms when the configured cooldown elapses (Cache
 * TTL), so a tenant who upgrades their plan / pays for more quota will
 * see traffic flow again without any manual intervention.
 *
 * Independent of the underlying buffer store implementation — Redis,
 * File and InMemory drivers all check this gate at the same boundaries.
 */
final class IngestCircuit
{
    public const CACHE_KEY = 'owlogs:circuit:tripped';

    /**
     * Trip the circuit for the configured cooldown.
     *
     * No-op if Cache is unavailable — the worst case is that the next
     * ship job will hit the same 403/429 and trip again.
     */
    public static function trip(int $status, ?string $reason = null): void
    {
        $cooldown = max(1, (int) config('owlogs.transport.circuit.cooldown_s', 300));

        $payload = json_encode([
            'status' => $status,
            'reason' => $reason,
            'tripped_at' => date('c'),
        ], JSON_UNESCAPED_UNICODE);

        try {
            Cache::put(self::CACHE_KEY, $payload, $cooldown);
        } catch (Throwable) {
            // Best-effort — Cache backend may be unavailable.
        }
    }

    /**
     * True when the circuit is currently tripped (within the cooldown
     * window). Defaults to false on any Cache error so an unavailable
     * Cache never silently blocks shipments.
     */
    public static function isTripped(): bool
    {
        if (! (bool) config('owlogs.transport.circuit.enabled', true)) {
            return false;
        }

        try {
            return Cache::has(self::CACHE_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Inspect the trip payload (status + reason + tripped_at) for
     * diagnostics. Returns null when the circuit is closed.
     *
     * @return array{status: int, reason: ?string, tripped_at: ?string}|null
     */
    public static function state(): ?array
    {
        try {
            $raw = Cache::get(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        return [
            'status' => (int) ($decoded['status'] ?? 0),
            'reason' => isset($decoded['reason']) ? (string) $decoded['reason'] : null,
            'tripped_at' => isset($decoded['tripped_at']) ? (string) $decoded['tripped_at'] : null,
        ];
    }

    /**
     * Manually close the circuit. Used by tests and admin tooling — the
     * normal recovery path is the Cache TTL expiring.
     */
    public static function reset(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // best-effort
        }
    }
}
