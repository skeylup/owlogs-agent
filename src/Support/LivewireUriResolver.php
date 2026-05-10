<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;

/**
 * Rewrites the `uri` context for Livewire update requests so the Owlogs UI
 * can group POST traffic by Livewire component instead of by the opaque
 * `/livewire-{hash}/update` endpoint.
 *
 * Without this, every interaction across every page lands on the SAME
 * route — making feature-level filtering impossible. With it, the URI
 * becomes:
 *
 *     POST /livewire — pages.admin.tenants.edit::save
 *
 * Wired in via the `owlogs.uri_resolver` config key (default = this class
 * since v0.x). To opt out, set `OWLOGS_URI_RESOLVER` to an empty string.
 */
class LivewireUriResolver
{
    /**
     * @param  array<string, bool>  $fields
     */
    public function __invoke(Request $request, array $fields): void
    {
        if (! ($fields['uri'] ?? true)) {
            return;
        }

        $path = $request->path();

        if (! str_starts_with($path, 'livewire-') && ! str_starts_with($path, 'livewire/')) {
            return;
        }

        $payload = $request->input('components', []);

        if (! is_array($payload) || $payload === []) {
            return;
        }

        // Multi-component batches are rare in practice — grab the first.
        $component = $payload[0] ?? null;
        $snapshotJson = $component['snapshot'] ?? null;
        $calls = $component['calls'] ?? [];

        $componentName = null;
        if (is_string($snapshotJson)) {
            $snapshot = json_decode($snapshotJson, true);
            $componentName = is_array($snapshot) ? ($snapshot['memo']['name'] ?? null) : null;
        }

        $methodCalls = array_values(array_filter(array_map(
            fn ($call) => is_array($call) && isset($call['method']) ? (string) $call['method'] : null,
            is_array($calls) ? $calls : [],
        )));

        $label = trim(
            ($componentName ?? 'livewire')
            .($methodCalls !== [] ? '::'.implode(',', $methodCalls) : '')
        );

        Context::add('uri', $request->method().' /livewire — '.$label);

        if ($componentName !== null) {
            Context::add('route_action', $componentName);
        }
    }
}
