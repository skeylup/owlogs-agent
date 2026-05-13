<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Regression test for the boot-ordering bug: Livewire snapshots its
 * registered ComponentHooks during its own boot() via
 * ComponentHookRegistry::boot(). Hooks added in OwlogsAgentServiceProvider::boot()
 * land too late and silently never fire. This test goes through the full
 * Livewire dispatch path to catch that class of bug.
 */
beforeEach(function (): void {
    foreach (['livewire_label', 'livewire_calls', 'route_action'] as $key) {
        Context::forgetHidden($key);
    }
});

class OwlogsHookTestComponent extends Component
{
    public int $count = 0;

    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    public function render(): string
    {
        return '<div>{{ $count }}</div>';
    }
}

it('the registered ComponentHook fires through the real Livewire dispatch', function (): void {
    Livewire::component('owlogs-hook-test', OwlogsHookTestComponent::class);

    Livewire::test('owlogs-hook-test')->call('increment', 3);

    expect(Context::getHidden('livewire_label'))->toBe('owlogs-hook-test::increment');
    expect(Context::getHidden('route_action'))->toBe('owlogs-hook-test::increment');
    // URI must be rewritten by the hook itself — not deferred — so logs
    // emitted from within the component action capture the feature-level URI.
    expect(Context::getHidden('uri'))->toContain('/livewire — owlogs-hook-test::increment');

    $calls = Context::getHidden('livewire_calls');
    expect($calls)->toBeArray()->toHaveCount(1);
    expect($calls[0]['component'])->toBe('owlogs-hook-test');
    expect($calls[0]['method'])->toBe('increment');
    expect($calls[0]['params'])->toBe([3]);
});

it('hydrate-only requests still get labelled with the component name', function (): void {
    Livewire::component('owlogs-hook-test-hydrate', OwlogsHookTestComponent::class);

    Livewire::test('owlogs-hook-test-hydrate')->set('count', 5);

    expect(Context::getHidden('livewire_label'))->toBe('owlogs-hook-test-hydrate');
});
