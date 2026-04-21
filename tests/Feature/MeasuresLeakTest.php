<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Skeylup\OwlogsAgent\Middleware\AddLogContext;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Bus::fake();
    unset($_SERVER['LARAVEL_OCTANE']);

    $this->store = new InMemoryLogBufferStore;
    $this->app->instance(LogBufferStore::class, $this->store);

    config(['owlogs.transport.batch_size' => 500]);
});

it('captures measures at log time, not flush time, so earlier logs do not inherit later measures', function (): void {
    Context::push('measures', ['label' => 'measureA', 'duration_ms' => 1.0, 'meta' => []]);
    Log::channel('owlogs')->info('first log');

    Context::push('measures', ['label' => 'measureB', 'duration_ms' => 2.0, 'meta' => []]);
    Log::channel('owlogs')->info('second log');

    app()->terminate();

    $rows = $this->store->drain(100);
    expect($rows)->toHaveCount(2);

    $firstMeasures = json_decode((string) $rows[0]['measures'], true);
    $secondMeasures = json_decode((string) $rows[1]['measures'], true);

    expect($firstMeasures)->toHaveCount(1)
        ->and($firstMeasures[0]['label'])->toBe('measureA');

    expect($secondMeasures)->toHaveCount(2)
        ->and(array_column($secondMeasures, 'label'))->toBe(['measureA', 'measureB']);
});

it('purges measures and breadcrumbs between scheduled tasks', function (): void {
    $task = Mockery::mock(ScheduleEvent::class);
    $task->shouldReceive('getSummaryForDisplay')->andReturn('TestTask');
    $task->command = 'test:task';

    Event::dispatch(new ScheduledTaskStarting($task));

    Context::push('measures', ['label' => 'db_query', 'duration_ms' => 12.3, 'meta' => []]);
    Context::push('breadcrumbs', 'TaskAction::run');

    expect(Context::get('measures'))->not->toBeNull();
    expect(Context::get('breadcrumbs'))->not->toBeNull();

    Event::dispatch(new ScheduledTaskFinished($task, 0.1));

    expect(Context::get('measures'))->toBeNull();
    expect(Context::get('breadcrumbs'))->toBeNull();
});

it('purges measures and breadcrumbs after CLI commands finish', function (): void {
    Event::dispatch(new CommandStarting('test:command', new ArrayInput([]), new NullOutput));

    Context::push('measures', ['label' => 'heavy_op', 'duration_ms' => 99.0, 'meta' => []]);
    Context::push('breadcrumbs', 'CommandAction::execute');

    Event::dispatch(new CommandFinished('test:command', new ArrayInput([]), new NullOutput, 0));

    expect(Context::get('measures'))->toBeNull();
    expect(Context::get('breadcrumbs'))->toBeNull();
});

it('purges measures and breadcrumbs at the start of a queue job', function (): void {
    Context::push('measures', ['label' => 'previous_job_leak', 'duration_ms' => 50.0, 'meta' => []]);
    Context::push('breadcrumbs', 'PreviousJob::trace');

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\TestJob');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('payload')->andReturn(['data' => []]);

    Event::dispatch(new JobProcessing('redis', $job));

    expect(Context::get('measures'))->toBeNull();
    expect(Context::get('breadcrumbs'))->toBeNull();
});

it('does not leak breadcrumbs across HTTP requests via AddLogContext', function (): void {
    Context::push('breadcrumbs', 'leftover_from_previous_request');
    Context::push('measures', ['label' => 'prev_request', 'duration_ms' => 10.0, 'meta' => []]);

    $middleware = new AddLogContext;
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, function ($req) {
        expect(Context::get('breadcrumbs'))->toBeNull();
        // AddLogContext pushes a `request` measure at end of handle(), so
        // during the inner closure (before $next returns) measures should
        // be null — confirming the defensive clear ran.
        expect(Context::get('measures'))->toBeNull();

        return new Response('ok');
    });
});
