<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Support;

use Illuminate\Support\Str;
use Skeylup\OwlogsAgent\Compat\ContextShim;

/**
 * Volume dials for the ingestion pipeline, enforced in the Monolog handler
 * path BEFORE a record is buffered — a sampled-out record never reaches the
 * RAM buffer, the cross-process store or the wire.
 *
 * Two independent gates, both read from config('owlogs.sampling'):
 *
 *  - `levels`: per-level keep probability (0.0–1.0, missing level = 1.0).
 *    Decided per row with mt_rand, so tests can seed via mt_srand() or
 *    inject a random source through the constructor.
 *  - `traces`: per-URI-pattern TRACE sampling ('pattern' => rate). The
 *    keep/drop decision is derived deterministically from the trace_id
 *    (hash → [0,1) fraction compared to the rate), so every row of a kept
 *    trace is kept — including queue jobs that inherit the trace via
 *    Laravel Context — and a dropped trace disappears entirely.
 *
 * Octane-safe: no request/config state in the constructor; config is read
 * per call (plain array lookups) and nothing accumulates across requests.
 */
class Sampler
{
    /** @var (callable(): float)|null */
    private $randomFloat;

    /**
     * @param  (callable(): float)|null  $randomFloat  Override for tests; must
     *                                                 return a float in [0, 1).
     *                                                 Defaults to mt_rand, which is
     *                                                 seedable via mt_srand().
     */
    public function __construct(?callable $randomFloat = null)
    {
        $this->randomFloat = $randomFloat;
    }

    /**
     * Decide whether the record being written should be kept, combining the
     * per-level rate with the trace decision for the ambient trace_id/uri.
     */
    public function shouldKeep(string $levelName): bool
    {
        if (! $this->shouldKeepLevel($levelName)) {
            return false;
        }

        $traceId = ContextShim::getHidden('trace_id');
        $uri = ContextShim::getHidden('uri');

        return $this->shouldKeepTrace(
            is_string($traceId) ? $traceId : null,
            is_string($uri) ? $uri : null,
        );
    }

    /**
     * Per-row level sampling. Rate 1.0 (the default) keeps everything,
     * 0.0 drops everything, anything in between is a weighted coin flip.
     */
    public function shouldKeepLevel(string $levelName): bool
    {
        $rates = (array) config('owlogs.sampling.levels', []);

        if ($rates === []) {
            return true;
        }

        $rate = $this->normalizeRate($rates[strtolower($levelName)] ?? 1.0);

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return $this->random() < $rate;
    }

    /**
     * Per-URI trace sampling. Deterministic per trace_id: the same trace id
     * always produces the same decision (in every process), so a sampled
     * trace keeps ALL its rows and a dropped one loses all of them.
     */
    public function shouldKeepTrace(?string $traceId, ?string $uri): bool
    {
        $patterns = (array) config('owlogs.sampling.traces', []);

        if ($patterns === []) {
            return true;
        }

        $rate = $this->traceRateFor($uri, $patterns);

        if ($rate === null || $rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        // No trace id (custom channel wiring, bare CLI without command
        // context): degrade to per-row sampling rather than keeping all.
        if ($traceId === null || $traceId === '') {
            return $this->random() < $rate;
        }

        return $this->traceFraction($traceId) < $rate;
    }

    /**
     * Rate of the first pattern matching the request path, or null when no
     * pattern matches (= keep). Patterns use Str::is wildcards against the
     * path without its leading slash, mirroring `ignored_uris`.
     *
     * @param  array<string, mixed>  $patterns
     */
    private function traceRateFor(?string $uri, array $patterns): ?float
    {
        $path = $this->pathFromUri($uri);

        if ($path === null) {
            return null;
        }

        foreach ($patterns as $pattern => $rate) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }

            if (Str::is(ltrim($pattern, '/'), $path)) {
                return $this->normalizeRate($rate);
            }
        }

        return null;
    }

    /**
     * Extract the path from the `uri` Context value, which is stored as
     * "METHOD full-url" by AddLogContext (and may be rewritten by the
     * Livewire/GraphQL integrations, e.g. "POST /livewire — comp::method").
     *
     * Parsed by hand — parse_url() mangles the multibyte em dash the
     * Livewire/GraphQL rewrites embed, producing invalid UTF-8 that would
     * silently break Str::is matching.
     */
    private function pathFromUri(?string $uri): ?string
    {
        if ($uri === null || $uri === '') {
            return null;
        }

        $spacePos = strpos($uri, ' ');
        $url = $spacePos !== false ? substr($uri, $spacePos + 1) : $uri;

        // Strip "scheme://host" when the uri holds a full URL.
        $schemePos = strpos($url, '://');
        if ($schemePos !== false) {
            $slashPos = strpos($url, '/', $schemePos + 3);
            $url = $slashPos === false ? '/' : substr($url, $slashPos);
        }

        // Cut the query string / fragment.
        $path = substr($url, 0, strcspn($url, '?#'));
        $path = ltrim($path, '/');

        return $path === '' ? null : $path;
    }

    /**
     * Map a trace id onto a stable fraction in [0, 1). md5 gives a uniform
     * spread and is process/machine independent, so HTTP workers and queue
     * workers in the same trace always agree.
     */
    private function traceFraction(string $traceId): float
    {
        return hexdec(substr(md5($traceId), 0, 8)) / 0xFFFFFFFF;
    }

    private function normalizeRate(mixed $rate): float
    {
        if (! is_numeric($rate)) {
            return 1.0;
        }

        return max(0.0, min(1.0, (float) $rate));
    }

    private function random(): float
    {
        if ($this->randomFloat !== null) {
            return ($this->randomFloat)();
        }

        return mt_rand() / mt_getrandmax();
    }
}
