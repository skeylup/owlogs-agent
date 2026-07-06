<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Flushing\OctaneWindowPolicy;
use Skeylup\OwlogsAgent\Handlers\RemoteHandlerV3;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;

beforeEach(function (): void {
    // Prevent the buffered ship job from being dispatched on flush.
    Bus::fake();
    Context::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @return array{0: RemoteHandlerV3, 1: InMemoryLogBufferStore}
 */
function makeDedupHandler(): array
{
    $store = new InMemoryLogBufferStore;

    return [new RemoteHandlerV3(store: $store), $store];
}

function makeExceptionRecord(string $message, Throwable $exception, Level $level = Level::Error): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'owlogs',
        level: $level,
        message: $message,
        context: ['exception' => $exception],
    );
}

it('collapses identical rows within a flush window into one row carrying count and first/last timestamps', function (): void {
    [$handler, $store] = makeDedupHandler();

    foreach (range(1, 3) as $i) {
        $handler->handle(makeLogRecord('storm', Level::Error));
    }

    expect($handler->bufferCount())->toBe(1);

    $handler->flush(true);
    $rows = $store->drain(10);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['message'])->toBe('storm')
        ->and($rows[0]['count'])->toBe(3)
        ->and($rows[0]['first_at'])->toBe($rows[0]['logged_at'])
        ->and($rows[0]['last_at'])->toBeString()
        ->and($rows[0]['last_at'] >= $rows[0]['first_at'])->toBeTrue()
        ->and($rows[0])->not->toHaveKey('suppressed_count');
});

it('leaves distinct rows untouched, each shipping with count 1', function (): void {
    [$handler, $store] = makeDedupHandler();

    $handler->handle(makeLogRecord('alpha', Level::Info));
    $handler->handle(makeLogRecord('beta', Level::Info));
    // Same message, different level → different fingerprint.
    $handler->handle(makeLogRecord('alpha', Level::Error));

    expect($handler->bufferCount())->toBe(3);

    $handler->flush(true);
    $rows = $store->drain(10);

    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row['count'])->toBe(1)
            ->and($row)->not->toHaveKeys(['first_at', 'last_at']);
    }
});

it('never collapses rows of different users', function (): void {
    [$handler] = makeDedupHandler();

    Context::addHidden('user_id', 1);
    $handler->handle(makeLogRecord('same failure', Level::Error));

    Context::addHidden('user_id', 2);
    $handler->handle(makeLogRecord('same failure', Level::Error));

    expect($handler->bufferCount())->toBe(2);
});

it('never collapses rows of different exception classes', function (): void {
    [$handler] = makeDedupHandler();

    $handler->handle(makeExceptionRecord('boom', new RuntimeException('boom')));
    $handler->handle(makeExceptionRecord('boom', new LogicException('boom')));
    $handler->handle(makeExceptionRecord('boom', new RuntimeException('boom')));

    expect($handler->bufferCount())->toBe(2);
});

it('does not collapse anything when dedup is disabled', function (): void {
    config(['owlogs.dedup.enabled' => false]);

    [$handler] = makeDedupHandler();

    foreach (range(1, 3) as $i) {
        $handler->handle(makeLogRecord('storm', Level::Error));
    }

    expect($handler->bufferCount())->toBe(3);
});

it('rate-caps a fingerprint across flush windows and ships a sampled row carrying suppressed_count', function (): void {
    config([
        'owlogs.dedup.max_per_minute' => 2,
        'owlogs.dedup.sample_interval_s' => 30,
    ]);

    [$handler, $store] = makeDedupHandler();

    // Each occurrence in its own flush window — the FPM storm shape
    // (one identical error per request, many requests per minute).
    $logAndFlush = function () use ($handler): void {
        $handler->handle(makeLogRecord('quota storm', Level::Error));
        $handler->flush(true);
    };

    // 1 & 2: under the cap, ship normally.
    $logAndFlush();
    $logAndFlush();
    // 3: over the cap, becomes the sample of the current interval.
    $logAndFlush();
    // 4 & 5: over the cap, sample slot taken → suppressed.
    $logAndFlush();
    $logAndFlush();

    expect($store->size())->toBe(3);

    // Next sample interval: the sampled row claims the suppressed tally.
    Carbon::setTestNow(Carbon::now()->addSeconds(31));
    $logAndFlush();

    $rows = $store->drain(10);

    expect($rows)->toHaveCount(4)
        ->and($rows[0])->not->toHaveKey('suppressed_count')
        ->and($rows[1])->not->toHaveKey('suppressed_count')
        ->and($rows[2])->not->toHaveKey('suppressed_count')
        ->and($rows[3]['suppressed_count'])->toBe(2)
        ->and($rows[3]['count'])->toBe(1);
});

it('does not rate-cap levels below cap_min_level', function (): void {
    config([
        'owlogs.dedup.max_per_minute' => 1,
        'owlogs.dedup.cap_min_level' => 'warning',
    ]);

    [$handler, $store] = makeDedupHandler();

    foreach (range(1, 5) as $i) {
        $handler->handle(makeLogRecord('chatty info', Level::Info));
        $handler->flush(true);
    }

    // Every window shipped its row — info is below the cap threshold.
    expect($store->size())->toBe(5);
});

it('resets collapse state on flush so windows never leak into each other under Octane', function (): void {
    $now = 1000.0;
    $policy = new OctaneWindowPolicy(function () use (&$now): float {
        return $now;
    });
    $store = new InMemoryLogBufferStore;
    $handler = new RemoteHandlerV3(policy: $policy, store: $store);

    // First window: three identical rows collapse into one.
    foreach (range(1, 3) as $i) {
        $now += 0.01;
        $handler->handle(makeLogRecord('octane storm', Level::Info));
    }

    expect($handler->bufferCount())->toBe(1);

    // Window elapses → the next identical write collapses (count 4)
    // and then triggers the due flush.
    $now += 2.1;
    $handler->handle(makeLogRecord('octane storm', Level::Info));

    expect($handler->bufferCount())->toBe(0);

    // Fresh window: the same fingerprint starts a NEW row — it must not
    // mutate the already-shipped one.
    $handler->handle(makeLogRecord('octane storm', Level::Info));
    $handler->handle(makeLogRecord('octane storm', Level::Info));

    expect($handler->bufferCount())->toBe(1);

    $handler->flush(true);
    $rows = $store->drain(10);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['count'])->toBe(4)
        ->and($rows[1]['count'])->toBe(2);
});

it('judges collapsed rows by last_at when dropping stale rows in the ship job', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    config([
        'owlogs.api_key' => 'test-key',
        'owlogs.transport.compression' => false,
        'owlogs.transport.buffer.max_age_s' => 60,
        'owlogs.transport.ship.batch_count' => 10,
    ]);

    $store = new InMemoryLogBufferStore;

    $staleAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('-120 seconds')->format('Y-m-d H:i:s.v');
    $freshAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->format('Y-m-d H:i:s.v');

    $store->append([
        // Collapsed row: first occurrence is stale but the storm is ongoing.
        ['message' => 'kept', 'count' => 5, 'logged_at' => $staleAt, 'first_at' => $staleAt, 'last_at' => $freshAt],
        // Plain stale row: dropped.
        ['message' => 'dropped', 'count' => 1, 'logged_at' => $staleAt],
    ]);

    (new ShipBufferedLogsJob)->handle($store);

    Http::assertSentCount(1);
    Http::assertSent(function ($request): bool {
        $logs = $request->data()['logs'] ?? [];

        // The stale drop is reported by a prepended synthetic diagnostic
        // row; the collapsed-but-ongoing row ships untouched behind it.
        return count($logs) === 2
            && str_starts_with((string) $logs[0]['message'], 'owlogs.agent.dropped_rows')
            && $logs[1]['message'] === 'kept'
            && $logs[1]['count'] === 5;
    });
});
