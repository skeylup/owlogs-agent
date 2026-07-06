<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\Support\Sampler;

it('keeps every level by default', function (): void {
    $sampler = new Sampler;

    foreach (['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL'] as $level) {
        expect($sampler->shouldKeepLevel($level))->toBeTrue();
    }
});

it('drops every record of a level with rate 0.0 and keeps other levels', function (): void {
    config(['owlogs.sampling.levels.debug' => 0.0]);

    $sampler = new Sampler;

    expect($sampler->shouldKeepLevel('DEBUG'))->toBeFalse();
    expect($sampler->shouldKeepLevel('INFO'))->toBeTrue();
    expect($sampler->shouldKeepLevel('ERROR'))->toBeTrue();
});

it('samples a fractional level rate against the injected random source', function (): void {
    config(['owlogs.sampling.levels.debug' => 0.4]);

    expect((new Sampler(fn (): float => 0.39))->shouldKeepLevel('DEBUG'))->toBeTrue();
    expect((new Sampler(fn (): float => 0.41))->shouldKeepLevel('DEBUG'))->toBeFalse();
});

it('clamps out-of-range rates and ignores non-numeric ones', function (): void {
    config(['owlogs.sampling.levels' => ['debug' => 5, 'info' => -1, 'notice' => 'nonsense']]);

    $sampler = new Sampler;

    expect($sampler->shouldKeepLevel('DEBUG'))->toBeTrue();
    expect($sampler->shouldKeepLevel('INFO'))->toBeFalse();
    expect($sampler->shouldKeepLevel('NOTICE'))->toBeTrue();
});

it('is deterministic under mt_srand seeding', function (): void {
    config(['owlogs.sampling.levels.debug' => 0.5]);

    $sampler = new Sampler;

    mt_srand(1337);
    $first = array_map(fn (): bool => $sampler->shouldKeepLevel('DEBUG'), range(1, 30));

    mt_srand(1337);
    $second = array_map(fn (): bool => $sampler->shouldKeepLevel('DEBUG'), range(1, 30));

    expect($first)->toBe($second);
    // With rate 0.5 over 30 draws both outcomes must appear.
    expect($first)->toContain(true)->toContain(false);
});

it('applies trace sampling only to URIs matching a pattern', function (): void {
    config(['owlogs.sampling.traces' => ['api/health*' => 0.0]]);

    $sampler = new Sampler;

    expect($sampler->shouldKeepTrace('trace-a', 'GET https://app.test/api/health'))->toBeFalse();
    expect($sampler->shouldKeepTrace('trace-a', 'GET https://app.test/api/health?probe=1'))->toBeFalse();
    expect($sampler->shouldKeepTrace('trace-a', 'GET https://app.test/api/orders'))->toBeTrue();
});

it('makes one deterministic decision per trace id across instances', function (): void {
    config(['owlogs.sampling.traces' => ['api/*' => 0.5]]);

    $uri = 'GET https://app.test/api/orders?page=2';
    $sampler = new Sampler;

    $decisions = array_map(
        fn (): bool => $sampler->shouldKeepTrace('trace-fixed', $uri),
        range(1, 25),
    );

    expect(array_unique($decisions))->toHaveCount(1);

    // A fresh instance (another worker / queue process) agrees.
    expect((new Sampler)->shouldKeepTrace('trace-fixed', $uri))->toBe($decisions[0]);
});

it('splits distinct traces between kept and dropped at a fractional rate', function (): void {
    config(['owlogs.sampling.traces' => ['api/*' => 0.5]]);

    $sampler = new Sampler;
    $kept = 0;

    foreach (range(1, 200) as $i) {
        if ($sampler->shouldKeepTrace('trace-'.$i, 'GET https://app.test/api/orders')) {
            $kept++;
        }
    }

    expect($kept)->toBeGreaterThan(0)->toBeLessThan(200);
});

it('falls back to per-row sampling when the trace id is missing', function (): void {
    config(['owlogs.sampling.traces' => ['api/*' => 0.5]]);

    expect((new Sampler(fn (): float => 0.49))->shouldKeepTrace(null, 'GET https://app.test/api/orders'))->toBeTrue();
    expect((new Sampler(fn (): float => 0.51))->shouldKeepTrace(null, 'GET https://app.test/api/orders'))->toBeFalse();
});

it('keeps everything when the uri is missing or no patterns are configured', function (): void {
    $sampler = new Sampler;

    expect($sampler->shouldKeepTrace('trace-a', 'GET https://app.test/api/anything'))->toBeTrue();

    config(['owlogs.sampling.traces' => ['api/*' => 0.0]]);

    expect((new Sampler)->shouldKeepTrace('trace-a', null))->toBeTrue();
});

it('matches trace patterns against Livewire-rewritten URIs', function (): void {
    config(['owlogs.sampling.traces' => ['livewire*' => 0.0]]);

    $sampler = new Sampler;

    expect($sampler->shouldKeepTrace('trace-a', 'POST /livewire — pages.dashboard::poll'))->toBeFalse();
});
