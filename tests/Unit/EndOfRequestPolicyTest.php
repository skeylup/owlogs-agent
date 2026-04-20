<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Skeylup\OwlogsAgent\Flushing\EndOfRequestPolicy;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Jobs\SendLogsJob;

beforeEach(function (): void {
    Bus::fake();
});

it('buffers freely below the hard ceiling without dispatching', function (): void {
    config(['owlogs.transport.batch_size' => 50]);

    $policy = new EndOfRequestPolicy;
    $handler = new RemoteHandler(policy: $policy);

    for ($i = 0; $i < 50; $i++) {
        $handler->handle(makeLogRecord("line {$i}"));
    }

    Bus::assertNotDispatched(SendLogsJob::class);
    expect($handler->bufferCount())->toBe(50);
});

it('flushes exactly once on onRequestBoundary', function (): void {
    config(['owlogs.transport.batch_size' => 50]);

    $policy = new EndOfRequestPolicy;
    $handler = new RemoteHandler(policy: $policy);

    for ($i = 0; $i < 10; $i++) {
        $handler->handle(makeLogRecord("line {$i}"));
    }

    Bus::assertNotDispatched(SendLogsJob::class);

    $policy->onRequestBoundary($handler);

    Bus::assertDispatchedTimes(SendLogsJob::class, 1);
    expect($handler->bufferCount())->toBe(0);
});

it('hard ceiling still fires under non-octane policy', function (): void {
    config(['owlogs.transport.batch_size' => 5]);

    $policy = new EndOfRequestPolicy;
    $handler = new RemoteHandler(policy: $policy);

    // batch_size=5 → hard ceiling at 5*2=10 records.
    for ($i = 0; $i < 11; $i++) {
        $handler->handle(makeLogRecord("line {$i}"));
    }

    Bus::assertDispatchedTimes(SendLogsJob::class, 1);
});
