<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Skeylup\OwlogsAgent\Transport\RedisLogBufferStore;

beforeEach(function (): void {
    $host = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port = getenv('REDIS_PORT') ?: 6379;

    config([
        'database.redis.client' => 'phpredis',
        'database.redis.owlogs_test' => [
            'host' => $host,
            'port' => $port,
            'database' => (int) (getenv('REDIS_DB') ?: 15),
        ],
    ]);

    try {
        Redis::connection('owlogs_test')->del('owlogs:test:buffer');
    } catch (Throwable $e) {
        $this->markTestSkipped('Redis unavailable: '.$e->getMessage());
    }
});

afterEach(function (): void {
    try {
        Redis::connection('owlogs_test')->del('owlogs:test:buffer');
    } catch (Throwable) {
        // ignore
    }
});

it('appends and drains atomically in FIFO order', function (): void {
    $store = new RedisLogBufferStore('owlogs_test', 'owlogs:test:buffer');

    $store->append([['msg' => 'a'], ['msg' => 'b']]);
    $store->append([['msg' => 'c']]);

    expect($store->size())->toBe(3);
    expect($store->drain(2))->toBe([['msg' => 'a'], ['msg' => 'b']]);
    expect($store->size())->toBe(1);
    expect($store->drain(10))->toBe([['msg' => 'c']]);
    expect($store->size())->toBe(0);
});

it('leaves the tail after a partial drain', function (): void {
    $store = new RedisLogBufferStore('owlogs_test', 'owlogs:test:buffer');

    $store->append([
        ['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4], ['n' => 5],
    ]);

    $drained = $store->drain(3);

    expect($drained)->toBe([['n' => 1], ['n' => 2], ['n' => 3]]);
    expect($store->size())->toBe(2);
});

it('does not lose rows appended concurrently with a drain call', function (): void {
    // Simulates the common race: one worker drains 2 while another
    // worker appends a 5th. The drain sees only the first 2, the
    // later LTRIM preserves positions 2..end, so the appended row
    // survives.
    $store = new RedisLogBufferStore('owlogs_test', 'owlogs:test:buffer');

    $store->append([['n' => 1], ['n' => 2], ['n' => 3], ['n' => 4]]);
    $store->append([['n' => 5]]);

    $drained = $store->drain(2);
    $remaining = $store->drain(10);

    expect($drained)->toBe([['n' => 1], ['n' => 2]]);
    expect($remaining)->toBe([['n' => 3], ['n' => 4], ['n' => 5]]);
});
