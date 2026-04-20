<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Skeylup\OwlogsAgent\Flushing\OctaneWindowPolicy;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Jobs\SendLogsJob;

beforeEach(function (): void {
    Bus::fake();

    config([
        'owlogs.transport.batch_size' => 50, // hard ceiling at 100 — well above the 20/2s window
        'owlogs.transport.octane.window_ms' => 2000,
        'owlogs.transport.octane.batch_count' => 20,
    ]);
});

it('does not flush below the batch count and inside the window', function (): void {
    $now = 1000.0;
    $policy = new OctaneWindowPolicy(function () use (&$now): float {
        return $now;
    });
    $handler = new RemoteHandler(policy: $policy);

    // 19 records in 1.999s → window not reached, count not reached.
    for ($i = 0; $i < 19; $i++) {
        $now += 0.1;
        $handler->handle(makeLogRecord("line {$i}"));
    }

    Bus::assertNotDispatched(SendLogsJob::class);
    expect($handler->bufferCount())->toBe(19);
});

it('flushes when the batch count is reached', function (): void {
    $now = 1000.0;
    $policy = new OctaneWindowPolicy(function () use (&$now): float {
        return $now;
    });
    $handler = new RemoteHandler(policy: $policy);

    // 20 records in quick succession — the 20th hits the count threshold.
    for ($i = 0; $i < 20; $i++) {
        $now += 0.01;
        $handler->handle(makeLogRecord("line {$i}"));
    }

    Bus::assertDispatchedTimes(SendLogsJob::class, 1);
    expect($handler->bufferCount())->toBe(0);
});

it('flushes when the window elapses on the next write', function (): void {
    $now = 1000.0;
    $policy = new OctaneWindowPolicy(function () use (&$now): float {
        return $now;
    });
    $handler = new RemoteHandler(policy: $policy);

    $handler->handle(makeLogRecord('first'));
    $handler->handle(makeLogRecord('second'));

    Bus::assertNotDispatched(SendLogsJob::class);

    // Advance past the 2s window.
    $now += 2.001;

    $handler->handle(makeLogRecord('third'));

    Bus::assertDispatchedTimes(SendLogsJob::class, 1);
});

it('flushes on tick when the window has elapsed', function (): void {
    $now = 1000.0;
    $policy = new OctaneWindowPolicy(function () use (&$now): float {
        return $now;
    });
    $handler = new RemoteHandler(policy: $policy);

    $handler->handle(makeLogRecord('only'));

    // Tick before window expires → no flush.
    $now += 1.0;
    $policy->onTick($handler);
    Bus::assertNotDispatched(SendLogsJob::class);

    // Tick after the window expires → flush.
    $now += 1.1;
    $policy->onTick($handler);
    Bus::assertDispatchedTimes(SendLogsJob::class, 1);
});

it('resets the window after a flush so subsequent records start a fresh window', function (): void {
    $now = 1000.0;
    $policy = new OctaneWindowPolicy(function () use (&$now): float {
        return $now;
    });
    $handler = new RemoteHandler(policy: $policy);

    // Fill to 20 and flush.
    for ($i = 0; $i < 20; $i++) {
        $handler->handle(makeLogRecord("a{$i}"));
    }
    Bus::assertDispatchedTimes(SendLogsJob::class, 1);

    // New record writes 1.5s after the previous flush → still well within a fresh window.
    $now += 1.5;
    $handler->handle(makeLogRecord('fresh'));
    Bus::assertDispatchedTimes(SendLogsJob::class, 1);

    // Advance past 2s from THIS record and trigger the check.
    $now += 2.1;
    $policy->onTick($handler);
    Bus::assertDispatchedTimes(SendLogsJob::class, 2);
});

it('onRequestBoundary respects the window and does not force flush', function (): void {
    $now = 1000.0;
    $policy = new OctaneWindowPolicy(function () use (&$now): float {
        return $now;
    });
    $handler = new RemoteHandler(policy: $policy);

    $handler->handle(makeLogRecord('one'));

    // Boundary fires before the window elapses → no flush (cross-request batching).
    $policy->onRequestBoundary($handler);
    Bus::assertNotDispatched(SendLogsJob::class);
    expect($handler->bufferCount())->toBe(1);

    // Boundary fires after the window elapses → flush.
    $now += 2.1;
    $policy->onRequestBoundary($handler);
    Bus::assertDispatchedTimes(SendLogsJob::class, 1);
});

it('onWorkerStopping always force-flushes', function (): void {
    $policy = new OctaneWindowPolicy;
    $handler = new RemoteHandler(policy: $policy);

    $handler->handle(makeLogRecord('only'));

    $policy->onWorkerStopping($handler);

    Bus::assertDispatchedTimes(SendLogsJob::class, 1);
});
