<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Handlers;

use Skeylup\OwlogsAgent\Flushing\FlushPolicy;
use Skeylup\OwlogsAgent\Transport\LogBufferStore;

/**
 * Marker interface implemented by every Monolog-version-specific RemoteHandler
 * variant. Lets the ServiceProvider's `instanceof` check (used to locate the
 * active handler on the `owlogs` channel) work uniformly across Monolog 2/3,
 * even though the underlying Monolog `AbstractProcessingHandler::write()`
 * signature changed across major versions.
 */
interface RemoteHandlerInterface
{
    /**
     * Drain the in-memory buffer into the cross-process store and dispatch
     * a ship job. Implementations forward to the shared core trait.
     */
    public function flush(bool $force = false): void;

    public function bufferCount(): int;

    public function bufferBytes(): int;

    public function firstBufferedAt(): ?float;

    public function setPolicy(FlushPolicy $policy): void;

    public function setStore(LogBufferStore $store): void;
}
