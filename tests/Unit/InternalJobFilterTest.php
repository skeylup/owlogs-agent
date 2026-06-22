<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\AutoLogger;

/**
 * Invoke the private prefix matcher directly: it is a pure function of the
 * job class name, so reflection is the most reliable granularity (firing a
 * real JobQueued event depends on the queue driver and is intercepted by
 * Bus/Queue fakes).
 */
function invokeIsInternalJob(string $jobClass): bool
{
    return (new ReflectionMethod(AutoLogger::class, 'isInternalJob'))
        ->invoke(new AutoLogger, $jobClass);
}

it('treats Telescope jobs as internal so they never tag a business trace', function (): void {
    // Regression: ProcessPendingUpdates dispatched during the agent's own
    // ShipBufferedLogsJob terminating phase used to tag that span (and
    // mislabel the parent trace) with job_class=ShipBufferedLogsJob.
    expect(invokeIsInternalJob('Laravel\\Telescope\\Jobs\\ProcessPendingUpdates'))->toBeTrue();
});

it('still treats the agent and framework plumbing jobs as internal', function (): void {
    expect(invokeIsInternalJob('Skeylup\\OwlogsAgent\\Jobs\\ShipBufferedLogsJob'))->toBeTrue();
    expect(invokeIsInternalJob('Skeylup\\Owlogs\\Jobs\\IngestLogsJob'))->toBeTrue();
    expect(invokeIsInternalJob('Illuminate\\Queue\\CallQueuedClosure'))->toBeTrue();
    expect(invokeIsInternalJob('Illuminate\\Broadcasting\\BroadcastEvent'))->toBeTrue();
    expect(invokeIsInternalJob('Illuminate\\Events\\CallQueuedListener'))->toBeTrue();
    expect(invokeIsInternalJob('Laravel\\Scout\\Jobs\\MakeSearchable'))->toBeTrue();
});

it('keeps forwarding business jobs (nothing outside Telescope is newly filtered)', function (): void {
    expect(invokeIsInternalJob('App\\Jobs\\N8n'))->toBeFalse();
    // SendQueuedNotifications lives under Illuminate\Notifications\, which is
    // intentionally NOT in the internal set — it stays visible in traces.
    expect(invokeIsInternalJob('Illuminate\\Notifications\\SendQueuedNotifications'))->toBeFalse();
    expect(invokeIsInternalJob('Laravel\\Cashier\\Payment'))->toBeFalse();
});

it('honours additional prefixes from owlogs.ignored_jobs config', function (): void {
    config(['owlogs.ignored_jobs' => ['Acme\\Infra\\']]);

    expect(invokeIsInternalJob('Acme\\Infra\\HeartbeatJob'))->toBeTrue();
    // Hardcoded defaults still apply alongside configured prefixes...
    expect(invokeIsInternalJob('Laravel\\Telescope\\Jobs\\ProcessPendingUpdates'))->toBeTrue();
    // ...and unrelated jobs are still forwarded.
    expect(invokeIsInternalJob('App\\Jobs\\N8n'))->toBeFalse();
});

it('safely ignores a malformed ignored_jobs config value', function (): void {
    config(['owlogs.ignored_jobs' => 'not-an-array']);

    expect(invokeIsInternalJob('App\\Jobs\\N8n'))->toBeFalse();
    expect(invokeIsInternalJob('Laravel\\Telescope\\Jobs\\ProcessPendingUpdates'))->toBeTrue();
});
