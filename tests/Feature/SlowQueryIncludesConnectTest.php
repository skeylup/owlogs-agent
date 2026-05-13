<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Skeylup\OwlogsAgent\AutoLogger;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    // Prevent the buffered ship job from being dispatched on terminate
    // and ensure deterministic threshold for the slow_query listener.
    Bus::fake();
    config([
        'owlogs.auto_log.slow_query' => true,
        'owlogs.auto_log.slow_query_ms' => 0,
    ]);
});

/**
 * Drain only the slow_query rows from the in-memory buffer store.
 *
 * @return list<array<string, mixed>>
 */
function drainSlowQueryRows(): array
{
    $store = app(LogBufferStore::class);

    if (! $store instanceof InMemoryLogBufferStore) {
        return [];
    }

    return array_values(array_filter(
        $store->drain(500),
        fn (array $row): bool => str_starts_with((string) ($row['message'] ?? ''), 'db.slow_query'),
    ));
}

it('flags the first slow query on a connection as includes_connect and the next as not', function (): void {
    $connection = DB::connection();

    Event::dispatch(new QueryExecuted('select 1', [], 1234.0, $connection));
    Event::dispatch(new QueryExecuted('select 2', [], 1234.0, $connection));

    app()->terminate();

    $rows = drainSlowQueryRows();

    expect($rows)->toHaveCount(2);
    expect($rows[0]['message'])->toContain('(incl. connect)');
    expect(json_decode((string) $rows[0]['context'], true))
        ->toMatchArray(['includes_connect' => true]);

    expect($rows[1]['message'])->not->toContain('(incl. connect)');
    expect(json_decode((string) $rows[1]['context'], true))
        ->toMatchArray(['includes_connect' => false]);

    Bus::assertDispatchedTimes(ShipBufferedLogsJob::class, 1);
});

it('resets the first-query flag after the request terminates', function (): void {
    $connection = DB::connection();

    Event::dispatch(new QueryExecuted('select 1', [], 1234.0, $connection));
    app()->terminate();

    drainSlowQueryRows();

    // app()->terminating triggered AutoLogger::resetRequestState() — the
    // next request's first query should be tagged again.
    app(AutoLogger::class); // sanity: the singleton is wired

    Event::dispatch(new QueryExecuted('select 3', [], 1234.0, $connection));
    app()->terminate();

    $rows = drainSlowQueryRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['message'])->toContain('(incl. connect)');
    expect(json_decode((string) $rows[0]['context'], true))
        ->toMatchArray(['includes_connect' => true]);
});
