<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Transport;

use Throwable;

/**
 * File-backed buffer using a single JSONL file protected by advisory
 * flock(). One record per line. Drain reads up to $limit lines,
 * truncates the file, and writes the remaining tail back — all under
 * LOCK_EX so concurrent appenders serialize cleanly.
 *
 * Blocks concurrent appends for the duration of a drain, which is
 * acceptable because a drain handles at most $limit rows (256 by
 * default) — typically < 20 ms on local disk.
 */
final class FileLogBufferStore implements LogBufferStore
{
    public function __construct(private readonly string $path) {}

    public function append(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $this->ensureDirectory();

        $maxRows = (int) config('owlogs.transport.buffer.max_rows', 0);

        // Fast path: no cap → plain append, no read/rewrite.
        if ($maxRows <= 0) {
            $this->appendUnbounded($rows);

            return;
        }

        $this->appendBounded($rows, $maxRows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function appendUnbounded(array $rows): void
    {
        $fh = @fopen($this->path, 'ab');
        if ($fh === false) {
            return;
        }

        try {
            if (! flock($fh, LOCK_EX)) {
                return;
            }

            try {
                foreach ($rows as $row) {
                    $json = json_encode($row, JSON_UNESCAPED_UNICODE);
                    if ($json === false) {
                        continue;
                    }
                    @fwrite($fh, $json."\n");
                }
                @fflush($fh);
            } finally {
                @flock($fh, LOCK_UN);
            }
        } finally {
            @fclose($fh);
        }
    }

    /**
     * Bounded append: read existing lines, push new ones, drop the oldest
     * to keep at most $maxRows lines, rewrite the file atomically under
     * LOCK_EX. Same I/O cost as a drain, so acceptable for typical
     * payload sizes (≤ a few thousand rows).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function appendBounded(array $rows, int $maxRows): void
    {
        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            return;
        }

        try {
            if (! flock($fh, LOCK_EX)) {
                return;
            }

            try {
                rewind($fh);
                $existing = [];
                while (($line = fgets($fh)) !== false) {
                    $trimmed = rtrim($line, "\r\n");
                    if ($trimmed !== '') {
                        $existing[] = $trimmed;
                    }
                }

                $appended = [];
                foreach ($rows as $row) {
                    $json = json_encode($row, JSON_UNESCAPED_UNICODE);
                    if ($json === false) {
                        continue;
                    }
                    $appended[] = $json;
                }

                $all = array_merge($existing, $appended);
                if (count($all) > $maxRows) {
                    $all = array_slice($all, count($all) - $maxRows);
                }

                ftruncate($fh, 0);
                rewind($fh);

                if ($all !== []) {
                    @fwrite($fh, implode("\n", $all)."\n");
                    @fflush($fh);
                }
            } finally {
                @flock($fh, LOCK_UN);
            }
        } finally {
            @fclose($fh);
        }
    }

    public function drain(int $limit): array
    {
        if ($limit <= 0 || ! is_file($this->path)) {
            return [];
        }

        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            return [];
        }

        try {
            if (! flock($fh, LOCK_EX)) {
                return [];
            }

            try {
                rewind($fh);

                $lines = [];
                while (count($lines) < $limit && ($line = fgets($fh)) !== false) {
                    $trimmed = rtrim($line, "\r\n");
                    if ($trimmed !== '') {
                        $lines[] = $trimmed;
                    }
                }

                if ($lines === []) {
                    return [];
                }

                $remaining = stream_get_contents($fh);

                ftruncate($fh, 0);
                rewind($fh);

                if ($remaining !== false && $remaining !== '') {
                    @fwrite($fh, $remaining);
                    @fflush($fh);
                }
            } finally {
                @flock($fh, LOCK_UN);
            }
        } finally {
            @fclose($fh);
        }

        $rows = [];
        foreach ($lines as $json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public function size(): int
    {
        if (! is_file($this->path)) {
            return 0;
        }

        $fh = @fopen($this->path, 'rb');
        if ($fh === false) {
            return 0;
        }

        try {
            if (! flock($fh, LOCK_SH)) {
                return 0;
            }

            try {
                $count = 0;
                while (fgets($fh) !== false) {
                    $count++;
                }

                return $count;
            } finally {
                @flock($fh, LOCK_UN);
            }
        } finally {
            @fclose($fh);
        }
    }

    public function clear(): void
    {
        if (! is_file($this->path)) {
            return;
        }

        $fh = @fopen($this->path, 'c+');
        if ($fh === false) {
            return;
        }

        try {
            if (! flock($fh, LOCK_EX)) {
                return;
            }

            try {
                @ftruncate($fh, 0);
                @fflush($fh);
            } finally {
                @flock($fh, LOCK_UN);
            }
        } finally {
            @fclose($fh);
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            return;
        }

        try {
            @mkdir($dir, 0755, true);
        } catch (Throwable) {
            // Best effort — append() will fail cleanly if directory
            // creation didn't succeed.
        }
    }
}
