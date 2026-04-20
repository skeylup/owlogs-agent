<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Transport;

use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Redis-backed buffer using a single list key (LPUSH/RPUSH semantics).
 *
 * append()  → RPUSH each row as JSON
 * drain()   → atomic Lua script: LRANGE + LTRIM, returns up to $limit rows
 * size()    → LLEN
 *
 * The atomic drain is important when multiple ShipBufferedLogsJob runs
 * overlap (e.g. re-dispatch triggers a concurrent execution) — without
 * the Lua script, two workers could LRANGE the same window and produce
 * duplicate shipments.
 */
final class RedisLogBufferStore implements LogBufferStore
{
    private const DRAIN_SCRIPT = <<<'LUA'
local key = KEYS[1]
local limit = tonumber(ARGV[1])
local items = redis.call('LRANGE', key, 0, limit - 1)
local count = #items
if count > 0 then
    redis.call('LTRIM', key, count, -1)
end
return items
LUA;

    public function __construct(
        private readonly string $connection,
        private readonly string $key,
    ) {}

    public function append(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $encoded = [];
        foreach ($rows as $row) {
            $json = json_encode($row, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                continue;
            }
            $encoded[] = $json;
        }

        if ($encoded === []) {
            return;
        }

        try {
            Redis::connection($this->connection)->rpush($this->key, ...$encoded);
        } catch (Throwable) {
            // Silent: Redis may be momentarily unavailable. Losing a
            // buffered batch is preferable to cascading errors into the
            // caller's request path.
        }
    }

    public function drain(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        try {
            $raw = Redis::connection($this->connection)->eval(
                self::DRAIN_SCRIPT,
                1,
                $this->key,
                $limit,
            );
        } catch (Throwable) {
            return [];
        }

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $rows = [];
        foreach ($raw as $json) {
            $decoded = json_decode((string) $json, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    public function size(): int
    {
        try {
            return (int) Redis::connection($this->connection)->llen($this->key);
        } catch (Throwable) {
            return 0;
        }
    }
}
