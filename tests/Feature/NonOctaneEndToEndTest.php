<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;

beforeEach(function (): void {
    Bus::fake();
    unset($_SERVER['LARAVEL_OCTANE']);
    // Hard ceiling high enough that none of these tests trigger it.
    config(['owlogs.transport.batch_size' => 500]);
});

it('buffers a typical request without dispatching mid-request', function (): void {
    Log::channel('owlogs')->info('step 1');
    Log::channel('owlogs')->info('step 2');
    Log::channel('owlogs')->info('step 3');

    Bus::assertNotDispatched(ShipBufferedLogsJob::class);
});

it('flushes exactly once when the app terminates', function (): void {
    Log::channel('owlogs')->info('step 1');
    Log::channel('owlogs')->warning('step 2');
    Log::channel('owlogs')->error('step 3');

    app()->terminate();

    Bus::assertDispatchedTimes(ShipBufferedLogsJob::class, 1);
});

it('does not dispatch on terminate when nothing was logged', function (): void {
    app()->terminate();

    Bus::assertNotDispatched(ShipBufferedLogsJob::class);
});
