<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Contracts;

/**
 * Implement this interface on any Eloquent model to expose
 * metadata that will be included in log entries automatically.
 *
 * Example on a User model:
 *
 *   use Skeylup\OwlogsAgent\Contracts\HasLogContext;
 *
 *   class User extends Authenticatable implements HasLogContext
 *   {
 *       public function toLogContext(): array
 *       {
 *           return [
 *               'name' => $this->first_name . ' ' . $this->last_name,
 *               'email' => $this->email,
 *               'role' => $this->role,
 *           ];
 *       }
 *   }
 */
interface HasLogContext
{
    /**
     * Return an array of safe-to-log metadata for this model.
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array;

    /**
     * Return a human-readable label for this model in log displays.
     */
    public function getLogContextLabel(): string;
}
