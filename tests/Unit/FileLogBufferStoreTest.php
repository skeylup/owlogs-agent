<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\Transport\FileLogBufferStore;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/owlogs-test-'.uniqid().'/buffer.jsonl';
});

afterEach(function (): void {
    if (is_file($this->path)) {
        @unlink($this->path);
    }
    $dir = dirname($this->path);
    if (is_dir($dir)) {
        @rmdir($dir);
    }
});

it('appends and drains rows in FIFO order', function (): void {
    $store = new FileLogBufferStore($this->path);

    $store->append([
        ['message' => 'a'],
        ['message' => 'b'],
        ['message' => 'c'],
    ]);

    expect($store->size())->toBe(3);

    $drained = $store->drain(10);

    expect($drained)->toBe([
        ['message' => 'a'],
        ['message' => 'b'],
        ['message' => 'c'],
    ]);
    expect($store->size())->toBe(0);
});

it('drains only up to the limit and leaves the remainder', function (): void {
    $store = new FileLogBufferStore($this->path);

    $store->append([
        ['message' => 'a'],
        ['message' => 'b'],
        ['message' => 'c'],
        ['message' => 'd'],
        ['message' => 'e'],
    ]);

    $drained = $store->drain(2);

    expect($drained)->toBe([
        ['message' => 'a'],
        ['message' => 'b'],
    ]);
    expect($store->size())->toBe(3);

    $next = $store->drain(10);
    expect($next)->toBe([
        ['message' => 'c'],
        ['message' => 'd'],
        ['message' => 'e'],
    ]);
    expect($store->size())->toBe(0);
});

it('is a no-op when the file does not exist', function (): void {
    $store = new FileLogBufferStore($this->path);

    expect($store->size())->toBe(0);
    expect($store->drain(10))->toBe([]);
});

it('handles interleaved append and drain', function (): void {
    $store = new FileLogBufferStore($this->path);

    $store->append([['n' => 1], ['n' => 2]]);
    $store->drain(1);                              // drains n=1
    $store->append([['n' => 3]]);
    $drained = $store->drain(10);

    expect($drained)->toBe([['n' => 2], ['n' => 3]]);
});

it('creates the containing directory if missing', function (): void {
    $store = new FileLogBufferStore($this->path);
    $store->append([['x' => 1]]);

    expect(is_file($this->path))->toBeTrue();
    expect($store->size())->toBe(1);
});
