<?php

declare(strict_types=1);

use Skeylup\OwlogsAgent\Support\Redactor;

it('masks every key the legacy hardcoded lists protected', function (): void {
    $input = [
        // AddLogContext request-input except list
        'password' => 'hunter2',
        'password_confirmation' => 'hunter2',
        'current_password' => 'old',
        // AddLogContext / OwlogsLivewireHook substring patterns
        'client_secret' => 'sk-live',
        'access_token' => 'abc',
        'authorization' => 'Bearer abc',
        'cookie' => 'session=abc',
        'credit_card' => '4242424242424242',
        // AutoLogger model-attribute list
        'secret' => 'abc',
        'token' => 'abc',
        'api_key' => 'abc',
        'remember_token' => 'abc',
        'two_factor_secret' => 'abc',
        'two_factor_recovery_codes' => '["a","b"]',
        // Untouched
        'email' => 'kevin@example.com',
        'name' => 'Kevin',
    ];

    $redacted = (new Redactor)->redact($input);

    $safe = ['email', 'name'];

    foreach ($input as $key => $original) {
        if (in_array($key, $safe, true)) {
            expect($redacted[$key])->toBe($original);
        } else {
            expect($redacted[$key])->toBe('********');
        }
    }
});

it('matches key patterns case-insensitively', function (): void {
    $redacted = (new Redactor)->redact([
        'PASSWORD' => 'x',
        'Api_Key' => 'x',
        'AUTHORIZATION' => 'x',
    ]);

    expect($redacted['PASSWORD'])->toBe('********');
    expect($redacted['Api_Key'])->toBe('********');
    expect($redacted['AUTHORIZATION'])->toBe('********');
});

it('masks nested keys and whole subtrees under a sensitive key', function (): void {
    $redacted = (new Redactor)->redact([
        'user' => [
            'password' => 'hunter2',
            'bio' => 'hello',
        ],
        'oauth_tokens' => ['a', 'b'],
    ]);

    expect($redacted['user']['password'])->toBe('********');
    expect($redacted['user']['bio'])->toBe('hello');
    // A sensitive key masks its whole value, arrays included.
    expect($redacted['oauth_tokens'])->toBe('********');
});

it('leaves non-string, non-array values untouched under safe keys', function (): void {
    $exception = new RuntimeException('boom');

    $redacted = (new Redactor)->redact([
        'count' => 42,
        'ratio' => 0.5,
        'flag' => true,
        'nothing' => null,
        'exception' => $exception,
    ]);

    expect($redacted['count'])->toBe(42);
    expect($redacted['ratio'])->toBe(0.5);
    expect($redacted['flag'])->toBeTrue();
    expect($redacted['nothing'])->toBeNull();
    expect($redacted['exception'])->toBe($exception);
});

it('honours a config override of key_patterns and recompiles on change', function (): void {
    $redactor = new Redactor;

    // First call compiles the default rules.
    expect($redactor->redact(['password' => 'x'])['password'])->toBe('********');

    config(['owlogs.redaction.key_patterns' => ['ssn']]);

    $redacted = $redactor->redact(['ssn' => '123-45-6789', 'password' => 'now-visible']);

    expect($redacted['ssn'])->toBe('********');
    expect($redacted['password'])->toBe('now-visible');
});

it('applies value_regexes to string values regardless of key', function (): void {
    config(['owlogs.redaction.value_regexes' => ['/\b\d{16}\b/']]);

    $redacted = (new Redactor)->redact([
        'note' => 'paid with card 4242424242424242 yesterday',
        'amount' => '19.99',
    ]);

    expect($redacted['note'])->toBe('paid with card ******** yesterday');
    expect($redacted['amount'])->toBe('19.99');
});

it('silently skips an invalid value regex', function (): void {
    config(['owlogs.redaction.value_regexes' => ['[not-a-regex', '/\d{16}/']]);

    $redacted = (new Redactor)->redact(['note' => 'card 4242424242424242']);

    expect($redacted['note'])->toBe('card ********');
});

it('uses a custom mask when configured', function (): void {
    config(['owlogs.redaction.mask' => '[redacted]']);

    $redacted = (new Redactor)->redact(['password' => 'hunter2']);

    expect($redacted['password'])->toBe('[redacted]');
});

it('exposes isSensitiveKey for call sites that need a key check', function (): void {
    $redactor = new Redactor;

    expect($redactor->isSensitiveKey('stripe_secret'))->toBeTrue();
    expect($redactor->isSensitiveKey('email'))->toBeFalse();
});
