<?php

declare(strict_types=1);

it('still defines the owlogs log channel when auto_register_stack is false', function (): void {
    expect(config('logging.channels.owlogs'))->toBeArray();
});

it('does not append owlogs to the stack channel when opted out', function (): void {
    expect(config('logging.channels.stack.channels'))
        ->toBeArray()
        ->not->toContain('owlogs');
});
