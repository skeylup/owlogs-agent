<?php

declare(strict_types=1);

use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Tests\AutoRegisterDisabledTestCase;
use Skeylup\OwlogsAgent\Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(TestCase::class)->in('Unit');
uses(AutoRegisterDisabledTestCase::class)->in('AutoRegisterDisabled');

function makeLogRecord(string $message = 'test', Level $level = Level::Info): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'owlogs',
        level: $level,
        message: $message,
    );
}
