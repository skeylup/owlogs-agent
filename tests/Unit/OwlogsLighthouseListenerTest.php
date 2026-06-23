<?php

declare(strict_types=1);

use GraphQL\Language\Parser;
use Illuminate\Support\Facades\Context;
use Nuwave\Lighthouse\Events\StartExecution;
use Skeylup\OwlogsAgent\GraphQL\OwlogsLighthouseListener;

/**
 * These tests exercise the real listener against real graphql-php AST nodes and
 * a real StartExecution instance. They are DORMANT in this package's CI because
 * nuwave/lighthouse is not a (dev) dependency — they skip cleanly until a
 * downstream project that ships Lighthouse runs the suite. They are NOT a
 * substitute for that downstream coverage.
 */
beforeEach(function (): void {
    if (! class_exists(StartExecution::class) || ! class_exists(Parser::class)) {
        $this->markTestSkipped('nuwave/lighthouse / graphql-php not installed.');
    }

    foreach (['graphql_label', 'graphql_operations', 'route_action', 'uri'] as $key) {
        Context::forgetHidden($key);
    }
});

/**
 * Build a StartExecution carrying a parsed document without touching its
 * (version-specific) constructor — the listener only reads `query` and
 * `operationName`, so we set just those via reflection.
 */
function makeStartExecution(string $query, ?string $operationName = null): StartExecution
{
    $event = (new ReflectionClass(StartExecution::class))->newInstanceWithoutConstructor();

    $document = Parser::parse($query);

    $reflection = new ReflectionObject($event);
    $reflection->getProperty('query')->setValue($event, $document);
    $reflection->getProperty('operationName')->setValue($event, $operationName);

    return $event;
}

it('rewrites the URI from the operation type and client operation name', function (): void {
    (new OwlogsLighthouseListener)->handle(
        makeStartExecution('mutation FooBar { createReport { id } }', 'FooBar')
    );

    expect(Context::getHidden('uri'))->toBe('POST /graphql — mutation FooBar');
    expect(Context::getHidden('route_action'))->toBe('mutation FooBar');
});

it('falls back to root field names when the operation is anonymous', function (): void {
    (new OwlogsLighthouseListener)->handle(
        makeStartExecution('query { me { id } settings { theme } }')
    );

    expect(Context::getHidden('uri'))->toBe('POST /graphql — query me, settings');
});

it('ignores introspection queries when ignore_introspection is on', function (): void {
    config(['owlogs.graphql.ignore_introspection' => true]);

    (new OwlogsLighthouseListener)->handle(
        makeStartExecution('query IntrospectionQuery { __schema { types { name } } }', 'IntrospectionQuery')
    );

    expect(Context::getHidden('uri'))->toBeNull();
    expect(Context::getHidden('graphql_operations'))->toBeNull();
});

it('keeps the first operation in the URI but stores every batched operation', function (): void {
    $listener = new OwlogsLighthouseListener;

    $listener->handle(makeStartExecution('mutation CreateReport { createReport { id } }', 'CreateReport'));
    $listener->handle(makeStartExecution('query ReportsList { reports { id } }', 'ReportsList'));

    expect(Context::getHidden('uri'))->toBe('POST /graphql — mutation CreateReport');

    $operations = Context::getHidden('graphql_operations');
    expect($operations)->toHaveCount(2);
    expect($operations[0]['name'])->toBe('CreateReport');
    expect($operations[1]['name'])->toBe('ReportsList');
});
