<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Tests\AutoRegisterDisabledTestCase;
use Skeylup\OwlogsAgent\Tests\TestCase;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');
uses(AutoRegisterDisabledTestCase::class)->in('AutoRegisterDisabled');

uses()->beforeEach(function (): void {
    // Tests that hit the full pipeline must not depend on Redis being
    // available on the CI box, and must not leak the debounce marker
    // between tests.
    config(['owlogs.transport.buffer_store' => 'memory']);
    app()->forgetInstance(LogBufferStore::class);
    Cache::flush();
})->in('Feature', 'Unit');

function makeLogRecord(string $message = 'test', Level $level = Level::Info): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'owlogs',
        level: $level,
        message: $message,
    );
}
