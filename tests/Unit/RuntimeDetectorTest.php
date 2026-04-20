<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\Flushing\RuntimeDetector;

afterEach(function (): void {
    unset($_SERVER['LARAVEL_OCTANE'], $_SERVER['OCTANE_SERVER']);
});

it('detects non-octane when no marker is set', function (): void {
    unset($_SERVER['LARAVEL_OCTANE']);

    expect(RuntimeDetector::isOctane())->toBeFalse();
});

it('detects octane when LARAVEL_OCTANE is "1"', function (): void {
    $_SERVER['LARAVEL_OCTANE'] = '1';

    expect(RuntimeDetector::isOctane())->toBeTrue();
});

it('treats LARAVEL_OCTANE=0 as non-octane', function (): void {
    $_SERVER['LARAVEL_OCTANE'] = '0';

    expect(RuntimeDetector::isOctane())->toBeFalse();
});

it('treats empty LARAVEL_OCTANE as non-octane', function (): void {
    $_SERVER['LARAVEL_OCTANE'] = '';

    expect(RuntimeDetector::isOctane())->toBeFalse();
});

it('reads the octane server from $_SERVER', function (): void {
    $_SERVER['OCTANE_SERVER'] = 'swoole';

    expect(RuntimeDetector::octaneServer())->toBe('swoole');
});

it('falls back to config for the octane server', function (): void {
    unset($_SERVER['OCTANE_SERVER']);
    config(['octane.server' => 'frankenphp']);

    expect(RuntimeDetector::octaneServer())->toBe('frankenphp');
});
