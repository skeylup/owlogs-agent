<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Transport;

/**
 * Process-local buffer. Used in tests and as a fallback when neither
 * Redis nor a writable filesystem is available. Not safe across
 * processes — a queue worker running ShipBufferedLogsJob will see an
 * empty store because it has its own instance.
 */
final class InMemoryLogBufferStore implements LogBufferStore
{
    /** @var list<array<string, mixed>> */
    private array $rows = [];

    public function append(array $rows): void
    {
        foreach ($rows as $row) {
            $this->rows[] = $row;
        }
    }

    public function drain(int $limit): array
    {
        if ($limit <= 0 || $this->rows === []) {
            return [];
        }

        $drained = array_slice($this->rows, 0, $limit);
        $this->rows = array_slice($this->rows, count($drained));

        return array_values($drained);
    }

    public function size(): int
    {
        return count($this->rows);
    }
}
