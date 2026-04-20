<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    $this->store = new InMemoryLogBufferStore;
    $this->app->instance(LogBufferStore::class, $this->store);

    config([
        'owlogs.api_key' => 'test-key',
        'owlogs.transport.ship.batch_count' => 3,
        'owlogs.transport.max_payload_bytes' => 512 * 1024,
    ]);

    Cache::add(ShipBufferedLogsJob::PENDING_CACHE_KEY, 1, now()->addSeconds(60));
});

it('drains up to batch_count, sends, and releases the cache marker', function (): void {
    $this->store->append([
        ['message' => 'a'],
        ['message' => 'b'],
        ['message' => 'c'],
    ]);

    expect(Cache::has(ShipBufferedLogsJob::PENDING_CACHE_KEY))->toBeTrue();

    (new ShipBufferedLogsJob)->handle($this->store);

    expect($this->store->size())->toBe(0);
    expect(Cache::has(ShipBufferedLogsJob::PENDING_CACHE_KEY))->toBeFalse();

    Http::assertSentCount(1);
});

it('self re-dispatches when the store has more rows than batch_count', function (): void {
    Bus::fake();

    // 5 rows, batch_count = 3 → first run ships 3, 2 remain, re-dispatches.
    $this->store->append([
        ['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4], ['n' => 5],
    ]);

    (new ShipBufferedLogsJob)->handle($this->store);

    expect($this->store->size())->toBe(2);
    Bus::assertDispatchedTimes(ShipBufferedLogsJob::class, 1);
});

it('is a no-op when the store is empty', function (): void {
    Bus::fake();

    (new ShipBufferedLogsJob)->handle($this->store);

    Bus::assertNotDispatched(ShipBufferedLogsJob::class);
    Http::assertNothingSent();
});

it('abandons silently when no api key is configured', function (): void {
    config(['owlogs.api_key' => '']);

    $this->store->append([['message' => 'x']]);

    (new ShipBufferedLogsJob)->handle($this->store);

    // Data was still drained but no HTTP call was made.
    expect($this->store->size())->toBe(0);
    Http::assertNothingSent();
});
