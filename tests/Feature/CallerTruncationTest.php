<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Monolog\Level;
use Skeylup\OwlogsAgent\Handlers\RemoteHandlerV3;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;

beforeEach(function (): void {
    Bus::fake();
    Context::flush();
});

it('truncates oversized caller_file and caller_method to the API limits', function (): void {
    $store = new InMemoryLogBufferStore;
    $handler = new RemoteHandlerV3(store: $store);

    $record = makeLogRecord('boom', Level::Error);
    $record = $record->with(extra: [
        'caller_file' => '/'.str_repeat('a', 600).'.php',
        'caller_line' => 12,
        'caller_method' => str_repeat('m', 300),
    ]);

    $handler->handle($record);
    $handler->flush(true);

    $rows = $store->drain(10);

    expect($rows)->toHaveCount(1)
        ->and(mb_strlen($rows[0]['caller_file']))->toBe(512)
        ->and(mb_strlen($rows[0]['caller_method']))->toBe(255);
});
