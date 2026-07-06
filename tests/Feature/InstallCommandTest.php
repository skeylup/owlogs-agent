<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->envPath = sys_get_temp_dir().'/owlogs-install-'.Str::random(12).'.env';
    file_put_contents($this->envPath, "APP_NAME=Demo\n");

    config(['owlogs.transport.ingest_url' => 'https://owlogs.test/api/owlogs/ingest']);
});

afterEach(function (): void {
    @unlink($this->envPath);
});

it('writes the api key and a non-default buffer store into the env file', function (): void {
    Http::fake();

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
        '--no-validate' => true,
        '--skip-doctor' => true,
    ])->assertExitCode(0);

    $contents = file_get_contents($this->envPath);

    expect($contents)
        ->toContain('OWLOGS_API_KEY=sk-live-123')
        ->toContain('OWLOGS_BUFFER_STORE=file')
        ->toContain('APP_NAME=Demo');

    Http::assertNothingSent();
});

it('updates existing OWLOGS_* lines in place without duplicating them', function (): void {
    Http::fake();

    file_put_contents(
        $this->envPath,
        "APP_NAME=Demo\nOWLOGS_API_KEY=old-key\nOWLOGS_BUFFER_STORE=redis\nMAIL_HOST=smtp.test\n",
    );

    $this->artisan('owlogs:install', [
        '--key' => 'new-key',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
        '--no-validate' => true,
        '--skip-doctor' => true,
    ])->assertExitCode(0);

    $contents = file_get_contents($this->envPath);

    expect($contents)
        ->toContain('OWLOGS_API_KEY=new-key')
        ->not->toContain('old-key')
        ->toContain('OWLOGS_BUFFER_STORE=file')
        ->toContain('MAIL_HOST=smtp.test');

    expect(substr_count($contents, 'OWLOGS_API_KEY='))->toBe(1);
    expect(substr_count($contents, 'OWLOGS_BUFFER_STORE='))->toBe(1);
});

it('does not append values matching the package defaults', function (): void {
    Http::fake();

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--buffer-store' => 'redis',
        '--queue' => 'default',
        '--env-file' => $this->envPath,
        '--no-validate' => true,
        '--skip-doctor' => true,
    ])->assertExitCode(0);

    $contents = file_get_contents($this->envPath);

    expect($contents)
        ->toContain('OWLOGS_API_KEY=sk-live-123')
        ->not->toContain('OWLOGS_BUFFER_STORE=')
        ->not->toContain('OWLOGS_QUEUE=');
});

it('validates the key against the ingest endpoint before writing', function (): void {
    Http::fake(['owlogs.test/*' => Http::response(['message' => 'The logs field is required.'], 422)]);

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
        '--skip-doctor' => true,
    ])
        ->expectsOutputToContain('API key accepted')
        ->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Api-Key', 'sk-live-123')
        && $request->url() === 'https://owlogs.test/api/owlogs/ingest');

    expect(file_get_contents($this->envPath))->toContain('OWLOGS_API_KEY=sk-live-123');
});

it('aborts without touching the env file when the key is rejected', function (): void {
    Http::fake(['owlogs.test/*' => Http::response(['message' => 'Invalid API key'], 401)]);

    $this->artisan('owlogs:install', [
        '--key' => 'bad-key',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
        '--skip-doctor' => true,
    ])
        ->expectsOutputToContain('rejected')
        ->assertExitCode(1);

    expect(file_get_contents($this->envPath))->not->toContain('OWLOGS_API_KEY');
});

it('continues with a warning when the endpoint is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
        '--skip-doctor' => true,
    ])
        ->expectsOutputToContain('Could not reach')
        ->assertExitCode(0);

    expect(file_get_contents($this->envPath))->toContain('OWLOGS_API_KEY=sk-live-123');
});

it('writes a custom ingest url and validates against it', function (): void {
    Http::fake(['self.hosted.test/*' => Http::response('', 422)]);

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--ingest-url' => 'https://self.hosted.test/api/owlogs/ingest',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
        '--skip-doctor' => true,
    ])->assertExitCode(0);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://self.hosted.test/api/owlogs/ingest');

    expect(file_get_contents($this->envPath))
        ->toContain('OWLOGS_INGEST_URL=https://self.hosted.test/api/owlogs/ingest');
});

it('falls back to the file buffer store when redis is unreachable', function (): void {
    Http::fake();

    config([
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => 1,
    ]);

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--env-file' => $this->envPath,
        '--no-validate' => true,
        '--skip-doctor' => true,
    ])
        ->expectsOutputToContain('Buffer store auto-detected: file')
        ->assertExitCode(0);

    expect(file_get_contents($this->envPath))->toContain('OWLOGS_BUFFER_STORE=file');
});

it('rejects an invalid --buffer-store value', function (): void {
    Http::fake();

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--buffer-store' => 'memory',
        '--env-file' => $this->envPath,
        '--no-validate' => true,
        '--skip-doctor' => true,
    ])->assertExitCode(1);

    expect(file_get_contents($this->envPath))->not->toContain('OWLOGS_API_KEY');
});

it('fails when no api key is provided', function (): void {
    Http::fake();

    $this->artisan('owlogs:install', [
        '--env-file' => $this->envPath,
        '--no-validate' => true,
        '--skip-doctor' => true,
    ])
        ->expectsQuestion('Workspace API key', '')
        ->assertExitCode(1);
});

it('emits test logs when requested', function (): void {
    Http::fake();

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
        '--no-validate' => true,
        '--emit-test-logs' => true,
        '--skip-doctor' => true,
    ])
        ->expectsOutputToContain('test_run_id=')
        ->assertExitCode(0);
});

it('ends by running the doctor checks', function (): void {
    Http::fake(['owlogs.test/*' => Http::response('', 422)]);

    $this->artisan('owlogs:install', [
        '--key' => 'sk-live-123',
        '--buffer-store' => 'file',
        '--env-file' => $this->envPath,
    ])
        ->expectsOutputToContain('Owlogs doctor — checking the shipping chain')
        ->expectsOutputToContain('owlogs:emit-test-logs')
        ->assertExitCode(0);
});
