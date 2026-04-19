<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\OwlogsAgentServiceProvider;

it('registers the service provider', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(OwlogsAgentServiceProvider::class);
});

it('merges the default config', function (): void {
    expect(config('owlogs'))->toBeArray()
        ->and(config('owlogs.enabled'))->not->toBeNull();
});

it('exposes a publishable config file', function (): void {
    expect(file_exists(__DIR__.'/../../config/owlogs.php'))->toBeTrue();
});
