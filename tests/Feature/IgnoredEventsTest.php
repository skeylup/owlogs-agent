<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use OwlogsAgentTestFixtures\Events\IgnoredFixtureEvent;
use OwlogsAgentTestFixtures\Events\KeptFixtureEvent;
use OwlogsAgentTestFixtures\Events\WildcardIgnoredEvent;
use Skeylup\OwlogsAgent\Jobs\ShipBufferedLogsJob;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    Bus::fake();
    config(['owlogs.auto_log.event_dispatch' => true]);
});

/**
 * @return list<array<string, mixed>>
 */
function drainEventDispatchRows(): array
{
    $store = app(LogBufferStore::class);

    if (! $store instanceof InMemoryLogBufferStore) {
        return [];
    }

    return array_values(array_filter(
        $store->drain(500),
        fn (array $row): bool => str_starts_with((string) ($row['message'] ?? ''), 'event.dispatched'),
    ));
}

it('drops events whose FQN matches ignored_events', function (): void {
    config(['owlogs.ignored_events' => [IgnoredFixtureEvent::class]]);

    Event::dispatch(new IgnoredFixtureEvent);
    Event::dispatch(new KeptFixtureEvent);

    app()->terminate();

    $rows = drainEventDispatchRows();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['message'])->toContain(class_basename(KeptFixtureEvent::class));
});

it('drops events whose FQN matches an ignored_events wildcard', function (): void {
    config(['owlogs.ignored_events' => ['OwlogsAgentTestFixtures\\Events\\Wildcard*']]);

    Event::dispatch(new WildcardIgnoredEvent);
    Event::dispatch(new KeptFixtureEvent);

    app()->terminate();

    $rows = drainEventDispatchRows();

    expect($rows)->toHaveCount(1);
    expect($rows[0]['message'])->toContain(class_basename(KeptFixtureEvent::class));

    Bus::assertDispatched(ShipBufferedLogsJob::class);
});

it('forwards every app event when ignored_events is empty', function (): void {
    config(['owlogs.ignored_events' => []]);

    Event::dispatch(new IgnoredFixtureEvent);
    Event::dispatch(new KeptFixtureEvent);

    app()->terminate();

    $rows = drainEventDispatchRows();

    expect($rows)->toHaveCount(2);
});
