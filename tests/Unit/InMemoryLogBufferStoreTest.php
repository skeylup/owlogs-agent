<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;

it('appends and drains in FIFO order', function (): void {
    $store = new InMemoryLogBufferStore;
    $store->append([['a' => 1], ['a' => 2]]);
    $store->append([['a' => 3]]);

    expect($store->size())->toBe(3);
    expect($store->drain(2))->toBe([['a' => 1], ['a' => 2]]);
    expect($store->size())->toBe(1);
    expect($store->drain(10))->toBe([['a' => 3]]);
    expect($store->size())->toBe(0);
});

it('is a no-op when draining empty', function (): void {
    $store = new InMemoryLogBufferStore;

    expect($store->drain(10))->toBe([]);
    expect($store->size())->toBe(0);
});
