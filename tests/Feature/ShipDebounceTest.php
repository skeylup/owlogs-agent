<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    Bus::fake();
    unset($_SERVER['LARAVEL_OCTANE']);

    $this->store = new InMemoryLogBufferStore;
    $this->app->instance(LogBufferStore::class, $this->store);

    config(['owlogs.transport.ship.debounce_ms' => 10000]);
});

it('collapses many flushes in the debounce window to a single queued ship job', function (): void {
    // Simulate 5 separate "request boundaries" each flushing a small
    // batch — HERD-style: one HTTP request + 4 queue-job terminations
    // within the same debounce window.
    for ($i = 0; $i < 5; $i++) {
        Log::channel('owlogs')->info("boundary {$i}");
        app()->terminate();
    }

    Bus::assertDispatchedTimes(ShipBufferedLogsJob::class, 1);

    // All 5 rows accumulated in the shared store; one job will drain them.
    expect($this->store->size())->toBe(5);
});

it('allows a new ship dispatch once the marker is released', function (): void {
    Log::channel('owlogs')->info('first');
    app()->terminate();

    Bus::assertDispatchedTimes(ShipBufferedLogsJob::class, 1);

    // Simulate the ship job having run — it releases the marker at
    // handle() start.
    Cache::forget(ShipBufferedLogsJob::PENDING_CACHE_KEY);

    Log::channel('owlogs')->info('second');
    app()->terminate();

    Bus::assertDispatchedTimes(ShipBufferedLogsJob::class, 2);
});
