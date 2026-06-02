<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Skeylup\OwlogsAgent\Middleware\AddLogContext;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    // Prevent the buffered ship job from being dispatched on terminate.
    Bus::fake();
    Context::flush();
});

/**
 * Drain the buffered rows whose message starts with the given prefix.
 *
 * @return list<array<string, mixed>>
 */
function drainHttpRows(string $prefix): array
{
    $store = app(LogBufferStore::class);

    if (! $store instanceof InMemoryLogBufferStore) {
        return [];
    }

    return array_values(array_filter(
        $store->drain(500),
        fn (array $row): bool => str_starts_with((string) ($row['message'] ?? ''), $prefix),
    ));
}

/**
 * Run AddLogContext against a request whose downstream returns $response,
 * flush the buffer, and return the rows matching $prefix.
 *
 * @return list<array<string, mixed>>
 */
function handleAndDrain(Request $request, Response $response, string $prefix): array
{
    (new AddLogContext)->handle($request, fn () => $response);

    app()->terminate();

    return drainHttpRows($prefix);
}

it('logs a non-2xx response the app returns', function (): void {
    $rows = handleAndDrain(
        Request::create('/api/resource', 'GET'),
        new Response('boom', 500),
        'http.response',
    );

    expect($rows)->toHaveCount(1);
    expect($rows[0]['message'])->toBe('http.response: 500 GET api/resource');
    expect($rows[0]['level_name'])->toBe('ERROR');
    expect(json_decode((string) $rows[0]['context'], true))
        ->toMatchArray(['status' => 500, 'method' => 'GET', 'path' => 'api/resource']);
});

it('does not log a 3xx redirect by default (min_status 400)', function (): void {
    expect(handleAndDrain(Request::create('/old', 'GET'), new Response('', 302), 'http.response'))->toBeEmpty();
});

it('does not log a successful 200/201 response', function (): void {
    expect(handleAndDrain(Request::create('/ok', 'GET'), new Response('ok', 200), 'http.response'))->toBeEmpty();
    expect(handleAndDrain(Request::create('/made', 'POST'), new Response('', 201), 'http.response'))->toBeEmpty();
});

it('logs redirects only when http_response_min_status is lowered to 300', function (): void {
    config(['owlogs.auto_log.http_response_min_status' => 300]);

    $rows = handleAndDrain(Request::create('/old', 'GET'), new Response('', 302), 'http.response');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['message'])->toBe('http.response: 302 GET old');
    expect($rows[0]['level_name'])->toBe('NOTICE');
});

it('does not log responses when http_response is disabled', function (): void {
    config(['owlogs.auto_log.http_response' => false]);

    expect(handleAndDrain(Request::create('/api', 'GET'), new Response('', 404), 'http.response'))->toBeEmpty();
});

it('logs a middleware rejection rendered from an exception, tagged with the exception class', function (): void {
    $response = (new IlluminateResponse('', 403))->withException(new AccessDeniedHttpException('forbidden'));

    $rejections = handleAndDrain(Request::create('/admin', 'GET'), $response, 'http.rejected');

    expect($rejections)->toHaveCount(1);
    expect($rejections[0]['message'])->toBe('http.rejected: 403 GET admin — AccessDeniedHttpException');
    expect($rejections[0]['level_name'])->toBe('WARNING');
    expect(json_decode((string) $rejections[0]['context'], true))
        ->toMatchArray([
            'status' => 403,
            'exception_class' => AccessDeniedHttpException::class,
        ]);

    // A rejection must not also surface as a plain http.response line.
    expect(drainHttpRows('http.response'))->toBeEmpty();
});

it('ignores a 5xx rendered from an exception (left to Laravel exception reporting)', function (): void {
    $response = (new IlluminateResponse('', 500))->withException(new RuntimeException('server boom'));

    expect(handleAndDrain(Request::create('/admin', 'GET'), $response, 'http.rejected'))->toBeEmpty();
    expect(drainHttpRows('http.response'))->toBeEmpty();
});

it('does not log rejections when middleware_rejection is disabled', function (): void {
    config(['owlogs.auto_log.middleware_rejection' => false]);

    $response = (new IlluminateResponse('', 403))->withException(new AccessDeniedHttpException);

    expect(handleAndDrain(Request::create('/admin', 'GET'), $response, 'http.rejected'))->toBeEmpty();
});

it('suppresses all logging for ignored URIs', function (): void {
    config(['owlogs.ignored_uris' => ['health']]);

    (new AddLogContext)->handle(Request::create('/health', 'GET'), fn () => new Response('down', 500));
    app()->terminate();

    expect(drainHttpRows('http.response'))->toBeEmpty();
    expect(drainHttpRows('http.rejected'))->toBeEmpty();
});

it('logs and re-throws when an exception escapes the pipeline (direct invocation)', function (): void {
    $thrown = false;

    try {
        (new AddLogContext)->handle(
            Request::create('/api/widgets', 'POST'),
            fn () => throw new HttpException(429, 'slow down'),
        );
    } catch (HttpException $e) {
        $thrown = true;
        expect($e->getStatusCode())->toBe(429);
    }

    app()->terminate();

    expect($thrown)->toBeTrue();

    $rows = drainHttpRows('http.rejected');
    expect($rows)->toHaveCount(1);
    expect($rows[0]['message'])->toBe('http.rejected: 429 POST api/widgets — HttpException');
    expect($rows[0]['level_name'])->toBe('WARNING');
});
