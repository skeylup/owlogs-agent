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
 *  - clear() atomically wipes all pending rows.
 *
 * Implementations must also enforce the optional `max_rows` cap configured
 * in `owlogs.transport.buffer.max_rows`: when an append() would push the
 * row count above the cap, the OLDEST rows are dropped to keep the buffer
 * bounded (FIFO retention of the newest data). The enforcement must be
 * atomic with the append itself so a busy producer can never momentarily
 * exceed the cap.
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

    /**
     * Atomically discard every pending row. Used when the ingest
     * circuit trips (server rejected our shipments with 403/429) so we
     * don't carry a doomed backlog through the cooldown window.
     */
    public function clear(): void;
}
