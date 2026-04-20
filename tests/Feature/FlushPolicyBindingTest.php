<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\Flushing\EndOfRequestPolicy;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Flushing\OctaneWindowPolicy;

afterEach(function (): void {
    unset($_SERVER['LARAVEL_OCTANE']);
});

it('binds EndOfRequestPolicy by default (non-octane)', function (): void {
    unset($_SERVER['LARAVEL_OCTANE']);
    app()->forgetInstance(FlushPolicy::class);

    expect(app(FlushPolicy::class))->toBeInstanceOf(EndOfRequestPolicy::class);
});

it('respects the flush_strategy config override', function (): void {
    config(['owlogs.transport.flush_strategy' => 'octane']);
    app()->forgetInstance(FlushPolicy::class);

    expect(app(FlushPolicy::class))->toBeInstanceOf(OctaneWindowPolicy::class);

    config(['owlogs.transport.flush_strategy' => 'end_of_request']);
    app()->forgetInstance(FlushPolicy::class);

    expect(app(FlushPolicy::class))->toBeInstanceOf(EndOfRequestPolicy::class);
});
