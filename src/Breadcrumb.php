<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

/**
 * @deprecated since 2026-05 — kept as a no-op for backwards compatibility.
 *
 * The breadcrumb feature has been retired in favour of {@see AutoLogger},
 * which emits standalone log lines tagged with a shared `trace_id` instead
 * of accumulating decorative steps into the next log row's `extra` payload.
 *
 * Filtering by `trace_id` in the Owlogs UI produces the same chronological
 * narrative without the per-record duplication and quota overhead the
 * breadcrumb chain used to incur.
 *
 * Every method here is intentionally silent (no-op, no deprecation warning
 * at boot) so existing call sites continue to work untouched while the
 * application is migrated.
 *
 * For custom business steps that no framework event captures, just emit a
 * regular log line — same correlation, less ceremony:
 *
 *   Log::info('[checkout.kyc_step_3_passed]', ['user_id' => $user->id]);
 */
class Breadcrumb
{
    /**
     * @deprecated No-op since the AutoLogger refactor. Emit a regular
     * `Log::info()` / `Log::debug()` line instead — trace correlation via
     * `trace_id` produces the same narrative.
     */
    public static function add(string $label, ?string $detail = null): void
    {
        // No-op.
    }

    /**
     * @deprecated No-op — always returns an empty list.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [];
    }

    /**
     * @deprecated No-op.
     */
    public static function clear(): void
    {
        // No-op.
    }
}
