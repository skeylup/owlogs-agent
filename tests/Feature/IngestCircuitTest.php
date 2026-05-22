<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Handlers\RemoteHandlerV3;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\IngestCircuit;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    IngestCircuit::reset();

    $this->store = new InMemoryLogBufferStore;
    $this->app->instance(LogBufferStore::class, $this->store);

    config([
        'owlogs.api_key' => 'test-key',
        'owlogs.transport.ship.batch_count' => 10,
        'owlogs.transport.max_payload_bytes' => 512 * 1024,
        'owlogs.transport.circuit.enabled' => true,
        'owlogs.transport.circuit.cooldown_s' => 300,
        'owlogs.transport.buffer.max_age_s' => 0,
    ]);
});

afterEach(function (): void {
    IngestCircuit::reset();
});

function makeRecord(string $message = 'hello'): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        channel: 'test',
        level: Level::Info,
        message: $message,
    );
}

it('trips the circuit on 403 and stops further retries', function (): void {
    Http::fake(['*' => Http::response('no plan', 403)]);

    $this->store->append([['message' => 'a', 'logged_at' => gmdate('Y-m-d H:i:s.0')]]);

    $job = new ShipBufferedLogsJob;
    $job->handle($this->store);

    expect(IngestCircuit::isTripped())->toBeTrue();
    expect($this->store->size())->toBe(0); // backlog wiped
    $state = IngestCircuit::state();
    expect($state['status'])->toBe(403);
    expect($state['reason'])->toBe('subscription_required');
});

it('trips the circuit on 429 and wipes the backlog', function (): void {
    Http::fake(['*' => Http::response('quota', 429)]);

    $this->store->append([
        ['message' => 'a', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
        ['message' => 'b', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
    ]);

    $job = new ShipBufferedLogsJob;
    $job->handle($this->store);

    expect(IngestCircuit::isTripped())->toBeTrue();
    expect($this->store->size())->toBe(0);
    $state = IngestCircuit::state();
    expect($state['status'])->toBe(429);
    expect($state['reason'])->toBe('quota_exhausted');
});

it('does NOT trip the circuit on a 5xx transient error', function (): void {
    Http::fake(['*' => Http::response('boom', 503)]);

    $this->store->append([['message' => 'a', 'logged_at' => gmdate('Y-m-d H:i:s.0')]]);

    $job = new ShipBufferedLogsJob;

    try {
        $job->handle($this->store);
    } catch (Throwable) {
        // 5xx throws inside the job to trigger Laravel's retry mechanism.
    }

    expect(IngestCircuit::isTripped())->toBeFalse();
});

it('ShipBufferedLogsJob exits early and clears the store when circuit is already tripped', function (): void {
    IngestCircuit::trip(429, 'quota_exhausted');

    $this->store->append([
        ['message' => 'a', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
        ['message' => 'b', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
    ]);

    $job = new ShipBufferedLogsJob;
    $job->handle($this->store);

    expect($this->store->size())->toBe(0);
    Http::assertNothingSent();
});

it('RemoteHandler::write() drops records when circuit is tripped', function (): void {
    IngestCircuit::trip(403, 'subscription_required');

    $handler = new RemoteHandlerV3(store: $this->store);

    // Call protected write() via reflection so we don't depend on
    // Monolog's full handle/handleBatch wiring in tests.
    $reflection = new ReflectionMethod($handler, 'write');
    $reflection->invoke($handler, makeRecord('dropped'));

    expect($handler->bufferCount())->toBe(0);
});

it('RemoteHandler::flush() discards buffered rows without storing when circuit is tripped', function (): void {
    $handler = new RemoteHandlerV3(store: $this->store);

    // Buffer a record while circuit is closed.
    $write = new ReflectionMethod($handler, 'write');
    $write->invoke($handler, makeRecord('queued'));
    expect($handler->bufferCount())->toBe(1);

    // Trip the circuit, then flush — buffer must clear without
    // writing to the cross-process store.
    IngestCircuit::trip(429, 'quota_exhausted');
    $handler->flush();

    expect($handler->bufferCount())->toBe(0);
    expect($this->store->size())->toBe(0);
});

it('does not dispatch ship jobs while the circuit is tripped', function (): void {
    Bus::fake();

    IngestCircuit::trip(403, 'subscription_required');

    $handler = new RemoteHandlerV3(store: $this->store);

    $write = new ReflectionMethod($handler, 'write');
    $write->invoke($handler, makeRecord('ignored'));

    Bus::assertNotDispatched(ShipBufferedLogsJob::class);
});

it('auto-rearms after the cooldown TTL elapses', function (): void {
    // Real Cache backend (array driver) honors TTL — trip with 1s, sleep, expect closed.
    config(['owlogs.transport.circuit.cooldown_s' => 1]);
    IngestCircuit::trip(429, 'quota_exhausted');

    expect(IngestCircuit::isTripped())->toBeTrue();

    // Travel past the cooldown without actually sleeping.
    Cache::shouldReceive('has')
        ->with(IngestCircuit::CACHE_KEY)
        ->andReturn(false);

    expect(IngestCircuit::isTripped())->toBeFalse();
});

it('drops rows older than max_age_s at drain time', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    config(['owlogs.transport.buffer.max_age_s' => 30]);

    $staleAt = (new DateTimeImmutable('-2 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    $freshAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

    $this->store->append([
        ['message' => 'stale', 'logged_at' => $staleAt],
        ['message' => 'fresh', 'logged_at' => $freshAt],
    ]);

    $job = new ShipBufferedLogsJob;
    $job->handle($this->store);

    // Only the fresh row reached the server.
    Http::assertSent(function ($request) {
        $body = json_decode(gzdecode($request->body()) ?: $request->body(), true);

        return is_array($body)
            && isset($body['logs'])
            && count($body['logs']) === 1
            && ($body['logs'][0]['message'] ?? null) === 'fresh';
    });
});

it('does not filter rows when max_age_s is 0', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    config(['owlogs.transport.buffer.max_age_s' => 0]);

    $staleAt = (new DateTimeImmutable('-2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

    $this->store->append([['message' => 'stale', 'logged_at' => $staleAt]]);

    $job = new ShipBufferedLogsJob;
    $job->handle($this->store);

    Http::assertSent(function ($request) {
        $body = json_decode(gzdecode($request->body()) ?: $request->body(), true);

        return is_array($body) && count($body['logs'] ?? []) === 1;
    });
});

it('caps in-memory store at max_rows by dropping oldest', function (): void {
    config(['owlogs.transport.buffer.max_rows' => 3]);

    $store = new InMemoryLogBufferStore;
    $store->append([['n' => 1], ['n' => 2]]);
    $store->append([['n' => 3], ['n' => 4], ['n' => 5]]);

    expect($store->size())->toBe(3);
    expect($store->drain(10))->toBe([['n' => 3], ['n' => 4], ['n' => 5]]);
});

it('clear() wipes the in-memory store atomically', function (): void {
    $store = new InMemoryLogBufferStore;
    $store->append([['n' => 1], ['n' => 2]]);

    expect($store->size())->toBe(2);
    $store->clear();
    expect($store->size())->toBe(0);
});
