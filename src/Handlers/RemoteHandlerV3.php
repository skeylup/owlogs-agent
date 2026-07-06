<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Skeylup\OwlogsAgent\Flushing\EndOfRequestPolicy;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Handlers\Concerns\BuffersAndShips;
use Skeylup\OwlogsAgent\Support\Redactor;
use Skeylup\OwlogsAgent\Support\Sampler;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

/**
 * Monolog 3 implementation. Selected by {@see RemoteLogChannel} when
 * `Monolog\Logger::API === 3` (Laravel 10+, plus the `^3.x` line of
 * Monolog backported into late Laravel 9.x).
 */
class RemoteHandlerV3 extends AbstractProcessingHandler implements RemoteHandlerInterface
{
    use BuffersAndShips;

    public function __construct(
        Level|int|string $level = Level::Debug,
        bool $bubble = true,
        ?FlushPolicy $policy = null,
        ?LogBufferStore $store = null,
    ) {
        parent::__construct($level, $bubble);

        $this->batchSize = (int) config('owlogs.transport.batch_size', 50);
        $this->maxPayloadBytes = (int) config('owlogs.transport.max_payload_bytes', 512 * 1024);
        $this->policy = $policy ?? new EndOfRequestPolicy;
        $this->store = $store ?? new InMemoryLogBufferStore;
    }

    protected function write(LogRecord $record): void
    {
        // Sampling gate — BEFORE buffering, so a sampled-out record never
        // costs buffer memory, store I/O or wire bytes.
        if (! app(Sampler::class)->shouldKeep($record->level->getName())) {
            return;
        }

        $redactor = app(Redactor::class);

        $this->bufferOne(
            channel: $record->channel,
            levelValue: $record->level->value,
            levelName: $record->level->getName(),
            message: $record->message,
            context: $redactor->redact($record->context),
            extra: $redactor->redact($record->extra),
            datetime: $record->datetime,
        );
    }

    public function close(): void
    {
        $this->flush(true);
        parent::close();
    }
}
