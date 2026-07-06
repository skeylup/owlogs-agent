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
 *   - {@see RemoteHandler::write()} drops new records BELOW the configured
 *     `circuit.retain_min_level` (default error); error/critical rows keep
 *     buffering within the store's FIFO cap. Dropped rows are tallied via
 *     {@see recordDropped()} and reported in one synthetic diagnostic row
 *     once the circuit closes.
 *   - {@see RemoteHandler::flush()} persists only the retained-severity
 *     slice to the cross-process store.
 *   - {@see RemoteHandler::scheduleShip()} does not dispatch ship jobs.
 *   - {@see ShipBufferedLogsJob::handle()} returns before doing any HTTP
 *     work but KEEPS the spool — rows survive the cooldown and are judged
 *     against `buffer.retry_max_age_s` on the first post-outage drain.
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

    /** Marks the outage + recovery window (trip cooldown + retry grace). */
    public const RECOVERY_KEY = 'owlogs:circuit:recovery';

    /** Per-reason drop tallies accumulated during an outage episode. */
    public const DROPPED_KEY_PREFIX = 'owlogs:circuit:dropped:';

    /**
     * Buckets tracked by {@see recordDropped()} / {@see claimDropped()}:
     *  - low_severity: sub-retain-level rows dropped while the circuit
     *    was tripped (write() and flush() gates in BuffersAndShips).
     *  - stale: rows discarded by the ship job's max-age filter.
     */
    public const DROP_REASONS = ['low_severity', 'stale'];

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
            // Recovery marker outlives the cooldown so the first ships after
            // re-arm judge staleness against `buffer.retry_max_age_s` instead
            // of wiping the whole retained backlog as "too old".
            Cache::put(self::RECOVERY_KEY, 1, $cooldown + self::retryMaxAgeS());
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
     * True while inside the outage/recovery window: circuit tripped OR the
     * cooldown recently elapsed. The ship job widens its stale-row cutoff to
     * `buffer.retry_max_age_s` during this window so a backlog retained
     * through the cooldown is not discarded on the first post-outage drain.
     */
    public static function inRecoveryWindow(): bool
    {
        try {
            return Cache::has(self::RECOVERY_KEY) || Cache::has(self::CACHE_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tally rows the agent dropped on the floor during an outage episode.
     * Claimed by the ship job once the circuit closes and shipped as ONE
     * synthetic diagnostic row — the tenant must never lose data silently.
     */
    public static function recordDropped(string $reason, int $count = 1): void
    {
        if ($count <= 0 || ! in_array($reason, self::DROP_REASONS, true)) {
            return;
        }

        $ttl = max(1, (int) config('owlogs.transport.circuit.cooldown_s', 300)) + self::retryMaxAgeS();

        try {
            $key = self::DROPPED_KEY_PREFIX.$reason;
            Cache::add($key, 0, $ttl);
            Cache::increment($key, $count);
        } catch (Throwable) {
            // Losing a tally only undercounts the diagnostic row.
        }
    }

    /**
     * Claim (read + clear) the accumulated drop tallies. Returns an empty
     * array when no drop episode happened — the common case.
     *
     * @return array<string, int> reason → dropped row count
     */
    public static function claimDropped(): array
    {
        $claimed = [];

        foreach (self::DROP_REASONS as $reason) {
            try {
                $count = (int) Cache::pull(self::DROPPED_KEY_PREFIX.$reason, 0);
            } catch (Throwable) {
                continue;
            }

            if ($count > 0) {
                $claimed[$reason] = $count;
            }
        }

        return $claimed;
    }

    /**
     * Manually close the circuit. Used by tests and admin tooling — the
     * normal recovery path is the Cache TTL expiring.
     */
    public static function reset(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::RECOVERY_KEY);
            foreach (self::DROP_REASONS as $reason) {
                Cache::forget(self::DROPPED_KEY_PREFIX.$reason);
            }
        } catch (Throwable) {
            // best-effort
        }
    }

    private static function retryMaxAgeS(): int
    {
        return max(0, (int) config('owlogs.transport.buffer.retry_max_age_s', 600));
    }
}
