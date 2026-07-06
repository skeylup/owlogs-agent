<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Monolog\Level;
use Skeylup\OwlogsAgent\Handlers\RemoteHandlerV3;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;

beforeEach(function (): void {
    // Prevent the buffered ship job from being dispatched on flush.
    Bus::fake();
    Context::flush();
});

/**
 * @return array{0: RemoteHandlerV3, 1: InMemoryLogBufferStore}
 */
function makeSamplingHandler(): array
{
    $store = new InMemoryLogBufferStore;

    return [new RemoteHandlerV3(store: $store), $store];
}

it('keeps everything with the default sampling config', function (): void {
    [$handler] = makeSamplingHandler();

    foreach ([Level::Debug, Level::Info, Level::Error] as $level) {
        $handler->handle(makeLogRecord('message', $level));
    }

    expect($handler->bufferCount())->toBe(3);
});

it('drops records of a sampled-out level before buffering', function (): void {
    config(['owlogs.sampling.levels.debug' => 0.0]);

    [$handler] = makeSamplingHandler();

    $handler->handle(makeLogRecord('debug noise', Level::Debug));
    expect($handler->bufferCount())->toBe(0);

    $handler->handle(makeLogRecord('kept', Level::Info));
    expect($handler->bufferCount())->toBe(1);
});

it('never lets a sampled-out record reach the buffer store', function (): void {
    config(['owlogs.sampling.levels.debug' => 0.0]);

    [$handler, $store] = makeSamplingHandler();

    $handler->handle(makeLogRecord('dropped', Level::Debug));
    $handler->flush(true);

    expect($store->size())->toBe(0);
});

it('samples a fractional level rate deterministically under mt_srand seeding', function (): void {
    config(['owlogs.sampling.levels.debug' => 0.5]);

    [$first] = makeSamplingHandler();
    mt_srand(42);
    foreach (range(1, 40) as $i) {
        $first->handle(makeLogRecord('row '.$i, Level::Debug));
    }

    [$second] = makeSamplingHandler();
    mt_srand(42);
    foreach (range(1, 40) as $i) {
        $second->handle(makeLogRecord('row '.$i, Level::Debug));
    }

    expect($second->bufferCount())->toBe($first->bufferCount());
    expect($first->bufferCount())->toBeGreaterThan(0)->toBeLessThan(40);
});

it('applies rate 0.0 and 1.0 trace patterns absolutely', function (): void {
    Context::addHidden('trace_id', 'trace-x');
    Context::addHidden('uri', 'GET https://app.test/api/polling/status');

    config(['owlogs.sampling.traces' => ['api/polling/*' => 0.0]]);
    [$dropped] = makeSamplingHandler();
    foreach (range(1, 3) as $i) {
        $dropped->handle(makeLogRecord('row '.$i, Level::Warning));
    }
    expect($dropped->bufferCount())->toBe(0);

    config(['owlogs.sampling.traces' => ['api/polling/*' => 1.0]]);
    [$kept] = makeSamplingHandler();
    foreach (range(1, 3) as $i) {
        $kept->handle(makeLogRecord('row '.$i, Level::Warning));
    }
    expect($kept->bufferCount())->toBe(3);
});

it('keeps or drops ALL rows of a trace — never half a trace', function (): void {
    config(['owlogs.sampling.traces' => ['api/exports/*' => 0.5]]);

    Context::addHidden('trace_id', 'trace-coherent');
    Context::addHidden('uri', 'GET https://app.test/api/exports/daily');

    [$handler] = makeSamplingHandler();
    foreach (range(1, 5) as $i) {
        $handler->handle(makeLogRecord('row '.$i, Level::Info));
    }

    expect($handler->bufferCount())->toBeIn([0, 5]);

    // A second handler (another worker in the same trace) agrees.
    [$other] = makeSamplingHandler();
    foreach (range(1, 5) as $i) {
        $other->handle(makeLogRecord('row '.$i, Level::Info));
    }

    expect($other->bufferCount())->toBe($handler->bufferCount());
});

it('leaves traces on non-matching URIs untouched', function (): void {
    config(['owlogs.sampling.traces' => ['api/polling/*' => 0.0]]);

    Context::addHidden('trace_id', 'trace-y');
    Context::addHidden('uri', 'GET https://app.test/dashboard');

    [$handler] = makeSamplingHandler();
    $handler->handle(makeLogRecord('kept', Level::Info));

    expect($handler->bufferCount())->toBe(1);
});
