<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Support;

/**
 * Central PII scrubber shared by every capture point in the agent:
 * request input (AddLogContext), model-change attributes (AutoLogger),
 * Livewire call params (OwlogsLivewireHook) and the log context/extra
 * bags (RemoteHandlerV2/V3, right before buffering).
 *
 * Rules come from config('owlogs.redaction'):
 *
 *  - `key_patterns`: case-insensitive substrings matched against array
 *    keys. A matching key has its whole value (nested arrays included)
 *    replaced by `mask`.
 *  - `value_regexes`: PCRE patterns applied to every string VALUE; each
 *    match is replaced by `mask`. Catches secrets hiding in free text.
 *  - `mask`: the replacement string.
 *
 * Octane-safe: the constructor takes no request/config state. Rules are
 * read lazily on first use and recompiled only when the underlying config
 * block actually changes, so the hot ingestion path pays a single array
 * comparison per call instead of a recompile.
 */
class Redactor
{
    private const DEFAULT_MASK = '********';

    /** @var array<string, mixed>|null Raw config block the compiled rules were built from. */
    private ?array $lastConfig = null;

    /** @var list<string> Lowercased key substrings. */
    private array $keyPatterns = [];

    /** @var list<string> Validated PCRE patterns. */
    private array $valueRegexes = [];

    private string $mask = self::DEFAULT_MASK;

    /**
     * Recursively mask sensitive keys and scrub string values.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        $this->syncRules();

        return $this->apply($data);
    }

    /**
     * True when the given key matches one of the configured key patterns.
     */
    public function isSensitiveKey(string $key): bool
    {
        $this->syncRules();

        return $this->matchesKeyPattern($key);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function apply(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->matchesKeyPattern($key)) {
                $data[$key] = $this->mask;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->apply($value);
            } elseif (is_string($value) && $this->valueRegexes !== []) {
                $data[$key] = $this->applyValueRegexes($value);
            }
        }

        return $data;
    }

    private function matchesKeyPattern(string $key): bool
    {
        if ($this->keyPatterns === []) {
            return false;
        }

        $lower = strtolower($key);

        foreach ($this->keyPatterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function applyValueRegexes(string $value): string
    {
        foreach ($this->valueRegexes as $regex) {
            $replaced = preg_replace($regex, $this->mask, $value);

            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        return $value;
    }

    /**
     * Recompile the rule set when config('owlogs.redaction') changed since
     * the last call. Cheap no-op (one array comparison) otherwise.
     */
    private function syncRules(): void
    {
        $config = (array) config('owlogs.redaction', []);

        if ($this->lastConfig === $config) {
            return;
        }

        $this->lastConfig = $config;

        $this->keyPatterns = [];
        foreach ((array) ($config['key_patterns'] ?? []) as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $this->keyPatterns[] = strtolower($pattern);
            }
        }

        $this->valueRegexes = [];
        foreach ((array) ($config['value_regexes'] ?? []) as $regex) {
            // Validate once at compile time so a malformed user regex can
            // never emit warnings (or fail open) on the per-record hot path.
            if (is_string($regex) && $regex !== '' && @preg_match($regex, '') !== false) {
                $this->valueRegexes[] = $regex;
            }
        }

        $mask = $config['mask'] ?? self::DEFAULT_MASK;
        $this->mask = is_string($mask) && $mask !== '' ? $mask : self::DEFAULT_MASK;
    }
}
