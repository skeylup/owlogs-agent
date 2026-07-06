<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Skeylup\OwlogsAgent\Transport\IngestCircuit;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    config([
        'owlogs.api_key' => 'test-key-1234',
        'owlogs.transport.ingest_url' => 'https://owlogs.test/api/owlogs/ingest',
    ]);
});

it('passes the chain with a valid key and suggests emit-test-logs', function (): void {
    Http::fake(['owlogs.test/*' => Http::response(['message' => 'The logs field is required.'], 422)]);

    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('key accepted by https://owlogs.test/api/owlogs/ingest')
        ->expectsOutputToContain('owlogs:emit-test-logs')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Api-Key', 'test-key-1234')
        && $request->url() === 'https://owlogs.test/api/owlogs/ingest');
});

it('fails without an api key and never pings the endpoint', function (): void {
    config(['owlogs.api_key' => null]);
    Http::fake();

    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('OWLOGS_API_KEY is empty')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('fails when the ingest endpoint rejects the key', function (): void {
    Http::fake(['owlogs.test/*' => Http::response(['message' => 'Invalid API key'], 401)]);

    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('API key rejected (401)')
        ->assertExitCode(1);
});

it('fails when the ingest endpoint is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('cannot reach https://owlogs.test/api/owlogs/ingest')
        ->assertExitCode(1);
});

it('reports a tripped circuit and closes it with --reset-circuit', function (): void {
    Http::fake(['owlogs.test/*' => Http::response('', 422)]);

    IngestCircuit::trip(403, 'subscription_required');

    // Single expectation on purpose: two expectsOutputToContain() matching
    // the same output line only satisfy one of the Mockery expectations.
    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('tripped (status 403, reason subscription_required')
        ->assertExitCode(1);

    expect(IngestCircuit::isTripped())->toBeTrue();

    $this->artisan('owlogs:doctor', ['--reset-circuit' => true])
        ->assertExitCode(0);

    expect(IngestCircuit::isTripped())->toBeFalse();
});

it('reports the pending buffered row count', function (): void {
    Http::fake(['owlogs.test/*' => Http::response('', 422)]);

    app(LogBufferStore::class)->append([
        ['message' => 'a'],
        ['message' => 'b'],
    ]);

    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('2 buffered row(s) pending')
        ->assertExitCode(0);
});

it('warns when no worker consumes the ship queue', function (): void {
    Http::fake(['owlogs.test/*' => Http::response('', 422)]);

    config([
        'cache.default' => 'file',
        'queue.default' => 'doctor-null',
        'queue.connections.doctor-null' => ['driver' => 'null'],
    ]);

    $this->artisan('owlogs:doctor', ['--queue-timeout' => 0])
        ->expectsOutputToContain('no worker picked up the probe')
        ->assertExitCode(0);
});

it('reports the sync queue driver as needing no worker', function (): void {
    Http::fake(['owlogs.test/*' => Http::response('', 422)]);

    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('sync driver runs jobs inline')
        ->assertExitCode(0);
});

it('fails when the redis buffer store is unreachable', function (): void {
    Http::fake(['owlogs.test/*' => Http::response('', 422)]);

    config([
        'owlogs.transport.buffer_store' => 'redis',
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
    ]);
    app()->forgetInstance(LogBufferStore::class);

    $this->artisan('owlogs:doctor')
        ->expectsOutputToContain('unreachable')
        ->assertExitCode(1);
});
