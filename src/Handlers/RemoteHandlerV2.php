<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use DateTimeImmutable;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Skeylup\OwlogsAgent\Flushing\EndOfRequestPolicy;
use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Handlers\Concerns\BuffersAndShips;
use Skeylup\OwlogsAgent\Transport\InMemoryLogBufferStore;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

/**
 * Monolog 2 implementation. Selected by {@see RemoteLogChannel} when
 * `Monolog\Logger::API === 2` (Laravel 8.65 → 9.49 without the Monolog 3
 * backport).
 *
 * Monolog 2 passes records as plain arrays and uses integer level constants
 * (`Logger::DEBUG = 100`, `Logger::INFO = 200`, …). The trait expects a
 * normalised component bag so we unwrap the array here once.
 */
class RemoteHandlerV2 extends AbstractProcessingHandler implements RemoteHandlerInterface
{
    use BuffersAndShips;

    public function __construct(
        int|string $level = Logger::DEBUG,
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

    /**
     * @param  array{message: string, context: array, level: int, level_name: string, channel: string, datetime: DateTimeImmutable, extra: array}  $record
     */
    protected function write(array $record): void
    {
        $this->bufferOne(
            channel: (string) ($record['channel'] ?? 'app'),
            levelValue: (int) ($record['level'] ?? 200),
            levelName: (string) ($record['level_name'] ?? 'INFO'),
            message: (string) ($record['message'] ?? ''),
            context: is_array($record['context'] ?? null) ? $record['context'] : [],
            extra: is_array($record['extra'] ?? null) ? $record['extra'] : [],
            datetime: $record['datetime'] instanceof \DateTimeInterface
                ? $record['datetime']
                : new DateTimeImmutable,
        );
    }

    public function close(): void
    {
        $this->flush(true);
        parent::close();
    }
}
