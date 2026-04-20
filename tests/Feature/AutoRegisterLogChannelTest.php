<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\Handlers\RemoteLogChannel;
use Skeylup\OwlogsAgent\LogContextTap;

it('defines the owlogs log channel on boot', function (): void {
    expect(config('logging.channels.owlogs'))->toBeArray()
        ->and(config('logging.channels.owlogs.driver'))->toBe('custom')
        ->and(config('logging.channels.owlogs.via'))->toBe(RemoteLogChannel::class)
        ->and(config('logging.channels.owlogs.tap'))->toBe([LogContextTap::class]);
});

it('appends owlogs to the stack channel by default', function (): void {
    expect(config('logging.channels.stack.channels'))
        ->toBeArray()
        ->toContain('owlogs');
});

it('does not duplicate owlogs when already present in stack', function (): void {
    $channels = (array) config('logging.channels.stack.channels');
    $occurrences = count(array_keys($channels, 'owlogs', true));

    expect($occurrences)->toBe(1);
});
