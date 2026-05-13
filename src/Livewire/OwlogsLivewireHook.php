<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Livewire;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Request;
use Livewire\ComponentHook;

/**
 * Captures Livewire component activity into the Owlogs Context so the
 * `/livewire-{hash}/update` endpoint can be logged as a feature-level URI
 * (e.g. `POST /livewire — pages.users.index::delete`) rather than an
 * opaque hash, and so the detail view can show which components/methods
 * actually ran during the request.
 *
 * Wired in only when `livewire/livewire` is installed — gated by
 * `class_exists()` in OwlogsAgentServiceProvider, so projects without
 * Livewire (classic Laravel, Inertia, etc.) pay zero cost.
 */
class OwlogsLivewireHook extends ComponentHook
{
    /** Cap stored call records so a runaway loop can't bloat the log row. */
    private const MAX_CALLS_STORED = 10;

    /** Per-param string cap inside `livewire_calls` to keep the row small. */
    private const MAX_PARAM_LENGTH = 256;

    /** Substrings that mark a param key as sensitive (matched case-insensitively). */
    private const SENSITIVE_PATTERNS = [
        'secret', 'token', 'password', 'key', 'authorization', 'cookie', 'credit_card',
    ];

    /**
     * Fires at the start of every subsequent component request (the
     * `/livewire/update` endpoint). Initial-render `mount` deliberately is
     * NOT hooked: we don't want to overwrite the host page's URI just
     * because it embeds a Livewire component.
     *
     * @param  array<string, mixed>  $memo
     */
    public function hydrate($memo): void
    {
        if (Context::hasHidden('livewire_label')) {
            return;
        }

        $name = $this->resolveName($memo);
        $this->applyLabel($name);
    }

    /**
     * Fires for each method invoked on the component during the request.
     * The most specific call wins for the label — if a batch executes
     * `save` then `close`, we keep the *last* method (matches how Livewire
     * resolves the user-visible action).
     *
     * @param  array<int, mixed>  $params
     */
    public function call($method, $params, $returnEarly, $metadata, $componentContext): void
    {
        $name = $this->resolveName();
        $label = $name.'::'.$method;
        $this->applyLabel($label);

        $existing = Context::getHidden('livewire_calls');
        if (is_array($existing) && count($existing) >= self::MAX_CALLS_STORED) {
            return;
        }

        Context::pushHidden('livewire_calls', [
            'component' => $name,
            'method' => (string) $method,
            'params' => $this->sanitizeParams(is_array($params) ? $params : []),
        ]);
    }

    /**
     * Write the label + rewrite the URI right away so any log emitted later
     * in the same request — model events, DB warnings, etc — captures the
     * feature-level URI rather than the opaque `/livewire-{hash}/update` one
     * the middleware seeded before Livewire took over.
     */
    private function applyLabel(string $label): void
    {
        Context::addHidden('livewire_label', $label);
        Context::addHidden('route_action', $label);

        $method = Request::method();
        Context::addHidden('uri', $method.' /livewire — '.$label);
    }

    /**
     * @param  array<string, mixed>|null  $memo
     */
    private function resolveName(?array $memo = null): string
    {
        if (is_array($memo) && isset($memo['name']) && is_string($memo['name'])) {
            return $memo['name'];
        }

        if ($this->component !== null && method_exists($this->component, 'getName')) {
            return (string) $this->component->getName();
        }

        return 'unknown';
    }

    /**
     * @param  array<mixed>  $params
     * @return array<mixed>
     */
    private function sanitizeParams(array $params): array
    {
        array_walk_recursive($params, function (&$value, $key): void {
            if (is_string($key)) {
                $lower = strtolower($key);
                foreach (self::SENSITIVE_PATTERNS as $pattern) {
                    if (str_contains($lower, $pattern)) {
                        $value = '********';

                        return;
                    }
                }
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_PARAM_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_PARAM_LENGTH).'…';
            }
        });

        return $params;
    }
}
