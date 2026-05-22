<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emit one of each kind of log the agent claims to support, tagged with a
 * shared `test_run_id`, so a playground harness can later query the server
 * and assert which kinds round-tripped end-to-end.
 *
 * Always exits 0 — any step that throws is caught and reported in the output
 * so we never break the matrix runner on a partial failure.
 */
class EmitTestLogsCommand extends Command
{
    protected $signature = 'owlogs:emit-test-logs
        {--run-id= : Shared correlation id; auto-generated when omitted}
        {--app-version= : Free-form label echoed in every log (e.g. "L9.52")}';

    protected $description = 'Emit one log of every supported kind so a sandbox can verify end-to-end ingestion.';

    public function handle(): int
    {
        $runId = (string) ($this->option('run-id') ?: Str::uuid());
        $appVersion = (string) ($this->option('app-version') ?: app()->version());

        $this->line("test_run_id={$runId}");
        $this->line("app_version={$appVersion}");

        $shared = [
            'test_run_id' => $runId,
            'app_version' => $appVersion,
        ];

        $steps = [
            'log_debug' => fn () => Log::debug('[owlogs.test] debug line', $shared + ['kind' => 'debug']),
            'log_info' => fn () => Log::info('[owlogs.test] info line', $shared + ['kind' => 'info']),
            'log_notice' => fn () => Log::notice('[owlogs.test] notice line', $shared + ['kind' => 'notice']),
            'log_warning' => fn () => Log::warning('[owlogs.test] warning line', $shared + ['kind' => 'warning']),
            'log_error' => fn () => Log::error('[owlogs.test] error line', $shared + ['kind' => 'error']),
            'log_critical' => fn () => Log::critical('[owlogs.test] critical line', $shared + ['kind' => 'critical']),
            'reported_exception' => function () use ($shared): void {
                try {
                    throw new RuntimeException('[owlogs.test] synthetic exception');
                } catch (\Throwable $e) {
                    Log::error('[owlogs.test] reported exception: '.$e->getMessage(), $shared + [
                        'kind' => 'exception',
                        'exception_class' => $e::class,
                        'exception_file' => $e->getFile().':'.$e->getLine(),
                    ]);
                }
            },
            'db_query' => function () use ($shared): void {
                $result = DB::select('select 1 as ok');
                Log::info('[owlogs.test] db query executed', $shared + [
                    'kind' => 'db_query',
                    'result' => (array) ($result[0] ?? []),
                ]);
            },
            'context_with_trace_id' => function () use ($shared): void {
                Log::info('[owlogs.test] custom trace context', $shared + [
                    'kind' => 'custom_trace',
                    'trace_id' => '01HZTESTTESTTESTTESTTEST00',
                    'span_id' => '01HZSPANSPANSPANSPANSPAN00',
                ]);
            },
        ];

        $results = [];
        foreach ($steps as $name => $step) {
            try {
                $step();
                $results[$name] = 'ok';
            } catch (\Throwable $e) {
                $results[$name] = 'fail: '.$e::class.': '.$e->getMessage();
            }
        }

        foreach ($results as $name => $status) {
            $this->line(sprintf('%-30s %s', $name, $status));
        }

        $failures = array_filter($results, fn (string $s): bool => $s !== 'ok');
        $this->newLine();
        $this->line(sprintf('emitted=%d failed=%d', count($results) - count($failures), count($failures)));

        return self::SUCCESS;
    }
}
