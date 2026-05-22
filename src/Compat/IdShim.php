<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Compat;

use Illuminate\Support\Str;

/**
 * Returns a 26-character lexicographically-sortable identifier suitable for
 * use as a trace_id / span_id.
 *
 * Laravel 9+ ships {@see Str::ulid()} which produces a
 * proper Crockford-Base32 ULID. On Laravel 8 the helper is absent, so we
 * fall back to a 26-character random string in the same length / charset
 * envelope (so log_entries varchar(26) columns accept either output).
 *
 * The fallback loses ULID's time-prefixed sortability but keeps the same
 * shape — perfectly fine for correlating logs within a single run.
 */
final class IdShim
{
    public static function ulid(): string
    {
        if (method_exists(Str::class, 'ulid')) {
            return (string) Str::ulid();
        }

        // 26 uppercase + digits — same charset width as a Crockford ULID
        // (without the timestamp prefix).
        return Str::upper(Str::random(26));
    }
}
