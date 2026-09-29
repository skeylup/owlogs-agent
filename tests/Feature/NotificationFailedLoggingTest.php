<?php

declare(strict_types=1);

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    Bus::fake();
    Context::flush();
});

final class FailingTestNotification extends Notification {}

/** A channel value object, like NotificationChannels\Expo\ExpoError. */
final readonly class TestChannelError
{
    public function __construct(public string $type, public string $message) {}
}

final class KeyedTestNotifiable
{
    public function getKey(): int
    {
        return 5784;
    }
}

/**
 * Fire a NotificationFailed event and return the buffered `notification.failed`
 * rows it produced.
 *
 * @return list<array<string, mixed>>
 */
function dispatchFailedNotification(mixed $notifiable, mixed $data): array
{
    Event::dispatch(new NotificationFailed($notifiable, new FailingTestNotification, 'expo', $data));

    app()->terminate();

    $store = app(LogBufferStore::class);

    if (! $store instanceof InMemoryLogBufferStore) {
        return [];
    }

    return array_values(array_filter(
        $store->drain(500),
        fn (array $row): bool => str_starts_with((string) ($row['message'] ?? ''), 'notification.failed'),
    ));
}

it('logs a channel error object instead of fataling on array access', function (): void {
    // Regression: Expo's ExpoChannel passes an ExpoError value object as $data,
    // which used to blow up the listener with "Cannot use object of type
    // NotificationChannels\Expo\ExpoError as array" and fail the whole job.
    $rows = dispatchFailedNotification(
        new KeyedTestNotifiable,
        new TestChannelError('DeviceNotRegistered', '"ExponentPushToken[xxx]" is not a registered push notification recipient'),
    );

    expect($rows)->toHaveCount(1);
    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray([
        'notifiable_id' => 5784,
        'error' => '"ExponentPushToken[xxx]" is not a registered push notification recipient',
    ]);
});

it('still reads the message out of the array payload most channels send', function (): void {
    $rows = dispatchFailedNotification(new KeyedTestNotifiable, ['message' => 'gateway refused']);

    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray(['error' => 'gateway refused']);
});

it('falls back to the error key, then to null', function (): void {
    $rows = dispatchFailedNotification(new KeyedTestNotifiable, ['error' => 'invalid number']);
    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray(['error' => 'invalid number']);

    $rows = dispatchFailedNotification(new KeyedTestNotifiable, ['status' => 500]);
    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray(['error' => null]);
});

it('skips an empty message and tries the next key', function (): void {
    $rows = dispatchFailedNotification(new KeyedTestNotifiable, ['message' => '', 'error' => 'invalid number']);

    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray(['error' => 'invalid number']);
});

it('logs null for payloads it cannot read a message from, without fataling', function (mixed $data): void {
    $rows = dispatchFailedNotification(new KeyedTestNotifiable, $data);

    expect($rows)->toHaveCount(1);
    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray(['error' => null]);
})->with([
    'null' => [null],
    'empty string' => [''],
    'object without message' => [new ArrayObject],
    'non-stringable message' => [['message' => ['nested' => 'x']]],
]);

it('unwraps an exception payload', function (): void {
    $rows = dispatchFailedNotification(new KeyedTestNotifiable, new RuntimeException('connection timed out'));

    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray(['error' => 'connection timed out']);
});

it('logs a keyless notifiable (on-demand routing) without fataling', function (): void {
    $rows = dispatchFailedNotification(new AnonymousNotifiable, 'plain string failure');

    expect($rows)->toHaveCount(1);
    expect(json_decode((string) $rows[0]['context'], true))->toMatchArray([
        'notifiable_id' => null,
        'error' => 'plain string failure',
    ]);
});
