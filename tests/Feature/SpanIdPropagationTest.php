<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Skeylup\OwlogsAgent\Middleware\AddLogContext;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Context::flush();
});

it('AddLogContext generates span_id distinct from trace_id', function (): void {
    $middleware = new AddLogContext;
    $request = Request::create('/test', 'GET');

    $middleware->handle($request, function () {
        return new Response('ok');
    });

    $traceId = Context::getHidden('trace_id');
    $spanId = Context::getHidden('span_id');

    expect($traceId)->toBeString()->not->toBeEmpty();
    expect($spanId)->toBeString()->not->toBeEmpty();
    expect($spanId)->not->toBe($traceId);
});

it('queue hydration captures dispatcher span_id as parent_span_id', function (): void {
    // Simulate a queue worker hydrating context from a payload serialized by
    // the dispatcher. Laravel runs each value through unserialize(), so the
    // payload stores them pre-serialized.
    $parentSpan = '01HYZZZZZZZZZZZZZZZZZZZZZA';
    $traceId = '01HYZZZZZZZZZZZZZZZZZZZZZB';

    Context::hydrate([
        'data' => [],
        'hidden' => [
            'trace_id' => serialize($traceId),
            'span_id' => serialize($parentSpan),
        ],
    ]);

    expect(Context::getHidden('parent_span_id'))->toBe($parentSpan);
    expect(Context::getHidden('span_id'))->toBeString()->not->toBe($parentSpan);
    expect(Context::getHidden('trace_id'))->toBe($traceId);
    expect(Context::getHidden('origin'))->toBe('queue');
});

it('queue hydration does not set parent_span_id when no span_id is in payload', function (): void {
    // A job dispatched from a process that never went through AddLogContext
    // (bare CLI script, external producer) has no span_id in its payload.
    // The hydration must still set up a fresh span_id and origin, with no
    // parent (parent_span_id stays null).
    Context::hydrate([
        'data' => [],
        'hidden' => [],
    ]);

    expect(Context::getHidden('parent_span_id'))->toBeNull();
    expect(Context::getHidden('span_id'))->toBeString()->not->toBeEmpty();
    expect(Context::getHidden('origin'))->toBe('queue');
});
