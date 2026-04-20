<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Transport;

/**
 * Cross-process log row buffer. Replaces "dispatch one SendLogsJob per
 * flush" by decoupling "which records are pending" (this store) from
 * "who ships them to Owlogs" (ShipBufferedLogsJob, dispatched with
 * debounce).
 *
 * Implementations must guarantee:
 *  - append() is safe to call from concurrent processes (atomic).
 *  - drain() atomically removes up to $limit rows in FIFO order — rows
 *    returned are no longer present on subsequent drain()/size() calls.
 *  - size() returns the current pending count.
 */
interface LogBufferStore
{
    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function append(array $rows): void;

    /**
     * @return list<array<string, mixed>>
     */
    public function drain(int $limit): array;

    public function size(): int;
}
