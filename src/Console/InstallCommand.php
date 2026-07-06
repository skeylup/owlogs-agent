<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Skeylup\OwlogsAgent\Handlers\RemoteHandler;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;
use Throwable;

/**
 * Guided installer: validates the workspace API key against the ingest
 * endpoint, writes/updates the OWLOGS_* block in .env diff-aware (only
 * values diverging from package defaults are appended; existing lines are
 * updated in place), picks a sensible buffer store for the detected
 * environment (redis when reachable, file otherwise), optionally emits a
 * batch of test logs and finishes by running `owlogs:doctor`.
 *
 * Collapses the manual copy-paste steps of the setup wizard into:
 *
 *   composer require skeylup/owlogs-agent
 *   php artisan owlogs:install --key=...
 */
class InstallCommand extends Command
{
    protected $signature = 'owlogs:install
        {--key= : Workspace API key written to OWLOGS_API_KEY}
        {--ingest-url= : Ingest endpoint URL (OWLOGS_INGEST_URL); omit for the hosted default}
        {--buffer-store= : Buffer store driver (redis|file); auto-detected when omitted}
        {--queue= : Queue name for the ship job (OWLOGS_QUEUE)}
        {--env-file= : Path to the .env file to update (defaults to the app env file)}
        {--no-validate : Skip validating the key against the ingest endpoint}
        {--emit-test-logs : Emit a batch of test logs once installed}
        {--skip-doctor : Skip running owlogs:doctor at the end}';

    protected $description = 'Install the Owlogs agent: validate the API key, write the OWLOGS_* .env block and run the doctor checks.';

    private const DEFAULT_INGEST_URL = 'https://www.owlogs.com/api/owlogs/ingest';

    /**
     * Package config defaults for the env keys this installer manages —
     * a value matching its default is never appended to .env (diff-aware,
     * mirroring the setup wizard's envBlock()).
     */
    private const PACKAGE_DEFAULTS = [
        'OWLOGS_BUFFER_STORE' => 'redis',
        'OWLOGS_QUEUE' => 'default',
    ];

    public function handle(): int
    {
        $key = trim((string) ($this->option('key') ?: $this->secret('Workspace API key') ?: ''));

        if ($key === '') {
            $this->error('An API key is required — pass it with --key= (find it on your workspace API keys page).');

            return self::FAILURE;
        }

        $ingestUrl = $this->option('ingest-url') !== null ? trim((string) $this->option('ingest-url')) : null;
        if ($ingestUrl === '') {
            $ingestUrl = null;
        }

        if (! $this->option('no-validate') && ! $this->validateKey($key, $ingestUrl)) {
            return self::FAILURE;
        }

        $store = $this->resolveBufferStore();
        if ($store === null) {
            return self::FAILURE;
        }

        $queue = $this->option('queue') !== null ? trim((string) $this->option('queue')) : null;
        if ($queue === '') {
            $queue = null;
        }

        $envPath = (string) ($this->option('env-file') ?: $this->laravel->environmentFilePath());

        if (! is_file($envPath)) {
            $this->error("Env file not found at {$envPath}.");

            return self::FAILURE;
        }

        /** @var array<string, array{value: string, default: ?string}> $managed */
        $managed = ['OWLOGS_API_KEY' => ['value' => $key, 'default' => null]];

        if ($ingestUrl !== null) {
            $managed['OWLOGS_INGEST_URL'] = ['value' => $ingestUrl, 'default' => null];
        }

        $managed['OWLOGS_BUFFER_STORE'] = ['value' => $store, 'default' => self::PACKAGE_DEFAULTS['OWLOGS_BUFFER_STORE']];

        if ($queue !== null) {
            $managed['OWLOGS_QUEUE'] = ['value' => $queue, 'default' => self::PACKAGE_DEFAULTS['OWLOGS_QUEUE']];
        }

        $this->newLine();
        $this->line("Updating {$envPath}");

        foreach ($this->applyEnvChanges($envPath, $managed) as $envKey => $action) {
            $this->line(sprintf('  %-22s %s', $envKey, $action));
        }

        if ($this->laravel->configurationIsCached()) {
            $this->warn('Config cache detected — run `php artisan config:clear` for the new values to take effect.');
        }

        $this->applyRuntimeConfig($key, $store, $ingestUrl, $queue);

        if ($this->option('emit-test-logs')) {
            $this->newLine();
            $this->call('owlogs:emit-test-logs');
        }

        if ($this->option('skip-doctor')) {
            return self::SUCCESS;
        }

        $this->newLine();

        return $this->call('owlogs:doctor') === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Authenticated ping: POST an EMPTY batch to the ingest endpoint. The
     * auth middleware runs before payload validation server-side, so a 422
     * proves the key was accepted without ingesting data; a 401 aborts the
     * install before a bad key is written to .env.
     */
    private function validateKey(string $key, ?string $ingestUrl): bool
    {
        $url = (string) ($ingestUrl ?: config('owlogs.transport.ingest_url') ?: self::DEFAULT_INGEST_URL);

        try {
            $response = RemoteHandler::suppressedWhile(
                fn () => Http::withHeaders([
                    'X-Api-Key' => $key,
                    'Accept' => 'application/json',
                ])->timeout(10)->post($url, ['logs' => []]),
            );
        } catch (Throwable $e) {
            $this->warn("Could not reach {$url} to validate the key (".$e->getMessage().') — continuing with the install.');

            return true;
        }

        $status = $response->status();

        if ($response->successful() || $status === 422) {
            $this->info("API key accepted by {$url}.");

            return true;
        }

        if ($status === 401) {
            $this->error("API key rejected by {$url} (401) — check the key against your workspace API keys page. Nothing was written.");

            return false;
        }

        if ($status === 403) {
            $this->warn('Key accepted but the workspace subscription is inactive (403) — logs will be refused until the plan is fixed. Continuing.');

            return true;
        }

        if ($status === 429) {
            $this->warn('Endpoint rate limited the validation ping (429) — continuing without confirmation.');

            return true;
        }

        $this->warn("Unexpected status {$status} from {$url} — continuing with the install.");

        return true;
    }

    /**
     * Explicit --buffer-store wins; otherwise pick redis when the configured
     * redis connection answers a ping, file everywhere else.
     */
    private function resolveBufferStore(): ?string
    {
        $option = $this->option('buffer-store');

        if ($option !== null) {
            $option = strtolower(trim((string) $option));

            if (! in_array($option, ['redis', 'file'], true)) {
                $this->error("Invalid --buffer-store '{$option}' — expected redis or file.");

                return null;
            }

            return $option;
        }

        $store = $this->redisIsReachable() ? 'redis' : 'file';
        $this->line("Buffer store auto-detected: {$store}.");

        return $store;
    }

    private function redisIsReachable(): bool
    {
        if (! config('database.redis')) {
            return false;
        }

        $connection = (string) config('owlogs.transport.redis_connection', 'default');

        try {
            $this->laravel->make('redis')->connection($connection)->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Diff-aware .env writer:
     *  - an existing OWLOGS_* line is updated in place when its value differs;
     *  - a missing key is appended only when its value diverges from the
     *    package default (defaults never clutter the file);
     *  - everything else in the file is left untouched.
     *
     * @param  array<string, array{value: string, default: ?string}>  $managed
     * @return array<string, string> env key => action taken
     */
    private function applyEnvChanges(string $envPath, array $managed): array
    {
        $contents = (string) file_get_contents($envPath);
        $appends = [];
        $actions = [];

        foreach ($managed as $envKey => $spec) {
            $pattern = '/^[ \t]*(?:export[ \t]+)?'.preg_quote($envKey, '/').'=(.*)$/m';

            if (preg_match($pattern, $contents, $matches)) {
                if ($this->unquoteEnvValue(trim($matches[1])) === $spec['value']) {
                    $actions[$envKey] = 'unchanged';

                    continue;
                }

                $line = $envKey.'='.$this->formatEnvValue($spec['value']);
                $contents = (string) preg_replace_callback($pattern, static fn (): string => $line, $contents);
                $actions[$envKey] = 'updated → '.$spec['value'];

                continue;
            }

            if ($spec['default'] !== null && $spec['value'] === $spec['default']) {
                $actions[$envKey] = 'skipped (package default)';

                continue;
            }

            $appends[] = $envKey.'='.$this->formatEnvValue($spec['value']);
            $actions[$envKey] = 'added → '.$spec['value'];
        }

        if ($appends !== []) {
            $contents = rtrim($contents, "\n");
            $contents .= ($contents === '' ? '' : "\n\n").'# Owlogs agent'."\n".implode("\n", $appends)."\n";
        }

        file_put_contents($envPath, $contents);

        return $actions;
    }

    /**
     * Update the already-merged runtime config so the emit-test-logs and
     * doctor steps in this same process use the values just written.
     */
    private function applyRuntimeConfig(string $key, string $store, ?string $ingestUrl, ?string $queue): void
    {
        config([
            'owlogs.api_key' => $key,
            'owlogs.transport.buffer_store' => $store,
        ]);

        if ($ingestUrl !== null) {
            config(['owlogs.transport.ingest_url' => $ingestUrl]);
        }

        if ($queue !== null) {
            config(['owlogs.transport.queue' => $queue]);
        }

        $this->laravel->forgetInstance(LogBufferStore::class);
    }

    private function formatEnvValue(string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_@.\/:+=\-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }

    private function unquoteEnvValue(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
        }

        if (strlen($value) >= 2 && str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
