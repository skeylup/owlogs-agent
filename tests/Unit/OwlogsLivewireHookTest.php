<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Skeylup\OwlogsAgent\Livewire\OwlogsLivewireHook;

function makeLivewireHookFor(string $componentName): OwlogsLivewireHook
{
    $hook = new OwlogsLivewireHook;
    $hook->setComponent(new class($componentName)
    {
        public function __construct(private string $name) {}

        public function getName(): string
        {
            return $this->name;
        }
    });

    return $hook;
}

beforeEach(function (): void {
    foreach (['livewire_label', 'livewire_calls', 'route_action'] as $key) {
        Context::forgetHidden($key);
    }
});

it('seeds a default label on hydrate from the memo name', function (): void {
    $hook = makeLivewireHookFor('pages.users.index');

    $hook->hydrate(['name' => 'pages.users.index', 'id' => 'abc123']);

    expect(Context::getHidden('livewire_label'))->toBe('pages.users.index');
    expect(Context::getHidden('route_action'))->toBe('pages.users.index');
});

it('falls back to component->getName() when memo lacks a name', function (): void {
    $hook = makeLivewireHookFor('pages.fallback');

    $hook->hydrate([]);

    expect(Context::getHidden('livewire_label'))->toBe('pages.fallback');
});

it('does not overwrite an existing label during hydrate', function (): void {
    Context::addHidden('livewire_label', 'already-set');

    makeLivewireHookFor('pages.users.index')->hydrate(['name' => 'pages.users.index']);

    expect(Context::getHidden('livewire_label'))->toBe('already-set');
});

it('records the called method and overrides the label on call', function (): void {
    $hook = makeLivewireHookFor('pages.users.index');
    $hook->hydrate(['name' => 'pages.users.index']);

    $hook->call('delete', [42], fn () => null, [], (object) []);

    expect(Context::getHidden('livewire_label'))->toBe('pages.users.index::delete');
    expect(Context::getHidden('route_action'))->toBe('pages.users.index::delete');

    $calls = Context::getHidden('livewire_calls');
    expect($calls)->toBeArray()->toHaveCount(1);
    expect($calls[0]['component'])->toBe('pages.users.index');
    expect($calls[0]['method'])->toBe('delete');
    expect($calls[0]['params'])->toBe([42]);
});

it('redacts sensitive keys in call params', function (): void {
    $hook = makeLivewireHookFor('pages.settings.security');

    $hook->call(
        'updatePassword',
        [['password' => 'hunter2', 'remember_token' => 'abc', 'safe' => 'ok']],
        fn () => null,
        [],
        (object) [],
    );

    $params = Context::getHidden('livewire_calls')[0]['params'];

    expect($params[0]['password'])->toBe('********');
    expect($params[0]['remember_token'])->toBe('********');
    expect($params[0]['safe'])->toBe('ok');
});

it('truncates oversized string params', function (): void {
    $hook = makeLivewireHookFor('pages.search');

    $long = str_repeat('a', 300);
    $hook->call('search', [$long], fn () => null, [], (object) []);

    $params = Context::getHidden('livewire_calls')[0]['params'];
    expect(mb_strlen($params[0]))->toBe(257); // 256 + the ellipsis
    expect(str_ends_with($params[0], '…'))->toBeTrue();
});

it('caps the number of recorded calls', function (): void {
    $hook = makeLivewireHookFor('pages.busy');

    for ($i = 0; $i < 15; $i++) {
        $hook->call('tick', [$i], fn () => null, [], (object) []);
    }

    expect(Context::getHidden('livewire_calls'))->toHaveCount(10);
});
