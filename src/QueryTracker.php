<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Tracks SQL queries during a request/job with caller resolution and N+1 detection.
 */
class QueryTracker
{
    /** @var array<string, int> */
    private array $queryCounts = [];

    /** @var array<string, true> */
    private array $reportedPatterns = [];

    private int $threshold;

    /** @var (callable(array): void)|null */
    private $onNPlusOne = null;

    /** @var list<string> */
    private array $ignorePaths = [
        '/vendor/',
        '/packages/skeylup/owlogs-agent/',
    ];

    public function __construct()
    {
        $this->threshold = (int) config('owlogs.measure.n_plus_one_threshold', 5);

        $callback = config('owlogs.measure.n_plus_one_callback');
        if ($callback !== null) {
            if (is_string($callback) && class_exists($callback)) {
                $callback = app($callback);
            }
            if (is_callable($callback)) {
                $this->onNPlusOne = $callback;
            }
        }
    }

    /**
     * Track a query event from DB::listen().
     *
     * @param  object{sql: string, bindings: array, time: float, connectionName: string}  $query
     */
    public function track(object $query): void
    {
        // Skip internal/system tables
        if (Str::contains($query->sql, ['log_entries', 'log_issues', 'log_issue_occurrences', 'log_embeddings', 'pg_', 'information_schema'])) {
            return;
        }

        $caller = $this->resolveCaller();

        // Skip queries from migrations
        if ($caller !== null && Str::contains($caller, ['/migrations/', 'Migration'])) {
            return;
        }
        $normalizedSql = $this->normalizeSql($query->sql);

        Context::pushHidden('measures', [
            'label' => 'db',
            'duration_ms' => round($query->time, 2),
            'meta' => [
                'sql' => Str::limit($query->sql, 300),
                'connection' => $query->connectionName,
                'caller' => $caller,
            ],
        ]);

        $this->queryCounts[$normalizedSql] = ($this->queryCounts[$normalizedSql] ?? 0) + 1;

        if ($this->queryCounts[$normalizedSql] === $this->threshold && ! isset($this->reportedPatterns[$normalizedSql])) {
            $this->reportedPatterns[$normalizedSql] = true;

            $pattern = [
                'sql' => Str::limit($normalizedSql, 500),
                'count' => $this->queryCounts[$normalizedSql],
                'caller' => $caller,
                'connection' => $query->connectionName,
                'trace_id' => Context::getHidden('trace_id'),
            ];

            Context::pushHidden('measures', [
                'label' => 'n+1',
                'duration_ms' => 0,
                'meta' => $pattern,
            ]);

            // Log as a visible WARNING entry in the same trace
            $table = $this->extractTableName($query->sql);
            $callerShort = $caller ? basename(str_replace(':', '#', $caller)) : '?';
            $message = "[n+1.detected] {$table} — {$callerShort}";

            Log::channel('owlogs')->warning($message, [
                'sql' => $pattern['sql'],
                'count' => $pattern['count'],
                'caller' => $pattern['caller'],
                'connection' => $pattern['connection'],
            ]);

            if ($this->onNPlusOne !== null) {
                try {
                    ($this->onNPlusOne)($pattern);
                } catch (\Throwable) {
                    // Don't let callback failures affect the request
                }
            }
        }
    }

    /**
     * Reset state between requests (Octane-safe).
     */
    public function reset(): void
    {
        $this->queryCounts = [];
        $this->reportedPatterns = [];
    }

    /**
     * @return array<string, int>
     */
    public function getNPlusOnePatterns(): array
    {
        return array_filter($this->queryCounts, fn (int $count) => $count >= $this->threshold);
    }

    /**
     * Extract the primary table name from a SQL query.
     */
    private function extractTableName(string $sql): string
    {
        // SELECT ... FROM "table"
        if (preg_match('/\bfrom\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            return $m[1];
        }
        // INSERT INTO "table"
        if (preg_match('/\binto\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            return $m[1];
        }
        // UPDATE "table"
        if (preg_match('/\bupdate\s+[`"]?(\w+)[`"]?/i', $sql, $m)) {
            return $m[1];
        }

        return 'unknown';
    }

    private function normalizeSql(string $sql): string
    {
        $sql = preg_replace("/'.+?'/", '?', $sql);
        $sql = preg_replace('/"[^"]+?"/', '?', $sql);
        $sql = preg_replace('/\b\d+\b/', '?', $sql);
        $sql = preg_replace('/IN\s*\(\?(?:,\s*\?)*\)/i', 'IN (?)', $sql);

        return $sql;
    }

    private function resolveCaller(): ?string
    {
        $basePath = base_path().'/';
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 20);

        foreach ($frames as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null) {
                continue;
            }

            $skip = false;
            foreach ($this->ignorePaths as $path) {
                if (str_contains($file, $path)) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if (str_starts_with($file, $basePath)) {
                $relative = substr($file, strlen($basePath));
                $line = $frame['line'] ?? '?';

                return "$relative:$line";
            }
        }

        return null;
    }
}
