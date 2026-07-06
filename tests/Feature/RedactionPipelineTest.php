<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Handlers\RemoteHandlerV3;
use Skeylup\OwlogsAgent\Livewire\OwlogsLivewireHook;
use Skeylup\OwlogsAgent\Middleware\AddLogContext;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

beforeEach(function (): void {
    // Prevent the buffered ship job from being dispatched on terminate.
    Bus::fake();
    Context::flush();
});

/**
 * Run a POST through AddLogContext and decode the captured request_input.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function captureInputThroughMiddleware(array $payload): array
{
    $request = Request::create('/profile', 'POST', $payload);

    (new AddLogContext)->handle($request, fn () => new IlluminateResponse('ok'));

    return (array) json_decode((string) Context::getHidden('request_input'), true);
}

it('masks the legacy sensitive request-input keys out of the box', function (): void {
    $input = captureInputThroughMiddleware([
        'password' => 'hunter2',
        'password_confirmation' => 'hunter2',
        'current_password' => 'old',
        'api_key' => 'sk-live',
        'profile' => ['client_secret' => 'shh', 'bio' => 'hello'],
        'name' => 'Kevin',
        '_token' => 'csrf-token',
    ]);

    expect($input['password'])->toBe('********');
    expect($input['password_confirmation'])->toBe('********');
    expect($input['current_password'])->toBe('********');
    expect($input['api_key'])->toBe('********');
    expect($input['profile']['client_secret'])->toBe('********');
    expect($input['profile']['bio'])->toBe('hello');
    expect($input['name'])->toBe('Kevin');
    expect($input)->not->toHaveKey('_token');
});

it('honours a config override for request-input redaction', function (): void {
    config(['owlogs.redaction.key_patterns' => ['ssn']]);

    $input = captureInputThroughMiddleware([
        'ssn' => '123-45-6789',
        'nickname' => 'kev',
    ]);

    expect($input['ssn'])->toBe('********');
    expect($input['nickname'])->toBe('kev');
});

it('masks sensitive log context and extra before the handler buffers them', function (): void {
    $store = new InMemoryLogBufferStore;
    $handler = new RemoteHandlerV3(store: $store);

    $handler->handle(new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'owlogs',
        level: Level::Info,
        message: 'user updated',
        context: ['password' => 'hunter2', 'plan' => 'pro'],
        extra: ['api_key' => 'sk-live'],
    ));

    $handler->flush(true);

    $rows = $store->drain(10);
    expect($rows)->toHaveCount(1);

    $context = json_decode((string) $rows[0]['context'], true);
    expect($context['password'])->toBe('********');
    expect($context['plan'])->toBe('pro');

    $extra = json_decode((string) $rows[0]['extra'], true);
    expect($extra['api_key'])->toBe('********');
});

it('masks sensitive model attributes in auto-logged model events', function (): void {
    $model = new class extends Model
    {
        protected $table = 'demo_users';

        protected $guarded = [];
    };

    $model->setRawAttributes([
        'id' => 7,
        'email' => 'kevin@example.com',
        'password' => 'a-bcrypt-hash',
        'remember_token' => 'tok',
    ]);

    Event::dispatch('eloquent.created: '.get_class($model), [$model]);

    app()->terminate();

    $rows = array_values(array_filter(
        app(LogBufferStore::class)->drain(500),
        fn (array $row): bool => str_starts_with((string) ($row['message'] ?? ''), 'model.created'),
    ));

    expect($rows)->toHaveCount(1);

    $context = json_decode((string) $rows[0]['context'], true);
    expect($context['attributes']['password'])->toBe('********');
    expect($context['attributes']['remember_token'])->toBe('********');
    expect($context['attributes']['email'])->toBe('kevin@example.com');
});

it('applies config-driven redaction to livewire call params', function (): void {
    config(['owlogs.redaction.key_patterns' => ['pin']]);

    $hook = new OwlogsLivewireHook;
    $hook->setComponent(new class
    {
        public function getName(): string
        {
            return 'pages.cards.activate';
        }
    });

    $hook->call('activate', [['pin' => '1234', 'label' => 'main card']], fn () => null, [], (object) []);

    $params = Context::getHidden('livewire_calls')[0]['params'];

    expect($params[0]['pin'])->toBe('********');
    expect($params[0]['label'])->toBe('main card');
});
