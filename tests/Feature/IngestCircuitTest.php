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

it('trips the circuit on 429 but retains the spool for a post-cooldown retry', function (): void {
    Http::fake(['*' => Http::response('quota', 429)]);

    $this->store->append([
        ['message' => 'a', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
        ['message' => 'b', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
    ]);

    $job = new ShipBufferedLogsJob;
    $job->handle($this->store);

    expect(IngestCircuit::isTripped())->toBeTrue();
    // 429 is temporary (quota resets / grace budget) — the failed chunk is
    // requeued instead of wiped so errors survive the cooldown.
    expect($this->store->size())->toBe(2);
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

it('ShipBufferedLogsJob exits early and KEEPS the spool when circuit is already tripped', function (): void {
    IngestCircuit::trip(429, 'quota_exhausted');

    $this->store->append([
        ['message' => 'a', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
        ['message' => 'b', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
    ]);

    $job = new ShipBufferedLogsJob;
    $job->handle($this->store);

    // No HTTP work while tripped, but the backlog survives the cooldown
    // (bounded by the FIFO cap + the retry_max_age_s stale filter).
    expect($this->store->size())->toBe(2);
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

    // The fresh row reached the server; the stale drop is no longer
    // silent — a synthetic diagnostic row reports it in the same batch.
    Http::assertSent(function ($request) {
        $body = json_decode(gzdecode($request->body()) ?: $request->body(), true);
        $messages = array_column($body['logs'] ?? [], 'message');

        return is_array($body)
            && count($messages) === 2
            && str_starts_with((string) $messages[0], 'owlogs.agent.dropped_rows')
            && $messages[1] === 'fresh';
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

it('keeps buffering error/critical rows during the cooldown and tallies dropped low-severity rows', function (): void {
    IngestCircuit::trip(429, 'quota_exhausted');

    $handler = new RemoteHandlerV3(store: $this->store);
    $write = new ReflectionMethod($handler, 'write');

    // Info is below the retain level → dropped on the floor, tallied.
    $write->invoke($handler, makeRecord('low severity chatter'));
    expect($handler->bufferCount())->toBe(0);

    // Error keeps buffering within the FIFO cap.
    $write->invoke($handler, makeLogRecord('boom', Level::Error));
    expect($handler->bufferCount())->toBe(1);

    expect(IngestCircuit::claimDropped())->toBe(['low_severity' => 1]);
});

it('flush() during the cooldown persists only the retained-severity slice to the store', function (): void {
    $handler = new RemoteHandlerV3(store: $this->store);
    $write = new ReflectionMethod($handler, 'write');

    // Buffer one info + one error while the circuit is still closed.
    $write->invoke($handler, makeRecord('pre-trip info'));
    $write->invoke($handler, makeLogRecord('pre-trip error', Level::Error));
    expect($handler->bufferCount())->toBe(2);

    IngestCircuit::trip(429, 'quota_exhausted');
    $handler->flush();

    expect($handler->bufferCount())->toBe(0);

    $rows = $this->store->drain(10);
    expect(count($rows))->toBe(1)
        ->and((int) $rows[0]['level'])->toBe(400)
        ->and($rows[0]['message'])->toBe('pre-trip error');

    expect(IngestCircuit::claimDropped())->toBe(['low_severity' => 1]);
});

it('emits ONE synthetic diagnostic row with the drop counts once the circuit closes', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    IngestCircuit::recordDropped('low_severity', 7);
    IngestCircuit::recordDropped('stale', 3);

    $this->store->append([
        ['message' => 'fresh error', 'level' => 400, 'level_name' => 'ERROR', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
    ]);

    (new ShipBufferedLogsJob)->handle($this->store);

    Http::assertSent(function ($request): bool {
        $body = json_decode(gzdecode($request->body()) ?: $request->body(), true);
        $logs = $body['logs'] ?? [];
        if (count($logs) !== 2) {
            return false;
        }

        $diag = $logs[0];
        $context = json_decode((string) ($diag['context'] ?? ''), true);

        return str_starts_with((string) $diag['message'], 'owlogs.agent.dropped_rows')
            && ($diag['level_name'] ?? null) === 'WARNING'
            && (($context['dropped']['low_severity'] ?? null) === 7)
            && (($context['dropped']['stale'] ?? null) === 3)
            && (($context['total'] ?? null) === 10)
            && ($logs[1]['message'] ?? null) === 'fresh error';
    });

    // Tallies were claimed — the next ship carries no diagnostic row.
    $this->store->append([
        ['message' => 'later row', 'logged_at' => gmdate('Y-m-d H:i:s.0')],
    ]);
    (new ShipBufferedLogsJob)->handle($this->store);

    Http::assertSentCount(2);
    $last = json_decode(gzdecode(Http::recorded()[1][0]->body()) ?: Http::recorded()[1][0]->body(), true);
    expect(count($last['logs']))->toBe(1)
        ->and($last['logs'][0]['message'])->toBe('later row');
});

it('ships the diagnostic row alone when the spool is empty after an episode', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    IngestCircuit::recordDropped('low_severity', 2);

    (new ShipBufferedLogsJob)->handle($this->store);

    Http::assertSent(function ($request): bool {
        $body = json_decode(gzdecode($request->body()) ?: $request->body(), true);
        $logs = $body['logs'] ?? [];

        return count($logs) === 1
            && str_starts_with((string) $logs[0]['message'], 'owlogs.agent.dropped_rows');
    });
});

it('widens the stale cutoff to retry_max_age_s inside the recovery window', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    config([
        'owlogs.transport.buffer.max_age_s' => 60,
        'owlogs.transport.buffer.retry_max_age_s' => 600,
    ]);

    // Simulate "cooldown elapsed moments ago": circuit closed, recovery
    // window still open.
    Cache::put(IngestCircuit::RECOVERY_KEY, 1, 600);

    $fiveMinOld = (new DateTimeImmutable('-5 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    $twentyMinOld = (new DateTimeImmutable('-20 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');

    $this->store->append([
        ['message' => 'ancient', 'logged_at' => $twentyMinOld],
        ['message' => 'retained backlog', 'logged_at' => $fiveMinOld],
    ]);

    (new ShipBufferedLogsJob)->handle($this->store);

    Http::assertSent(function ($request): bool {
        $body = json_decode(gzdecode($request->body()) ?: $request->body(), true);
        $messages = array_column($body['logs'] ?? [], 'message');

        // The 5-minute-old backlog survives (60s cutoff would have wiped
        // it); the 20-minute-old row is dropped and reported by the
        // prepended diagnostic row.
        return in_array('retained backlog', $messages, true)
            && ! in_array('ancient', $messages, true)
            && str_starts_with((string) ($messages[0] ?? ''), 'owlogs.agent.dropped_rows');
    });
});

it('trip() opens the recovery window and reset() closes it', function (): void {
    expect(IngestCircuit::inRecoveryWindow())->toBeFalse();

    IngestCircuit::trip(429, 'quota_exhausted');
    expect(IngestCircuit::inRecoveryWindow())->toBeTrue();

    IngestCircuit::reset();
    expect(IngestCircuit::inRecoveryWindow())->toBeFalse();
});
