<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent;

use Illuminate\Support\Facades\Context;

/**
 * Simple breadcrumb tracker using Laravel's Context stacks.
 *
 * Usage:
 *   Breadcrumb::add('CreateProjectAction::execute');
 *   Breadcrumb::add('GenerateDocumentAction::execute');
 *
 * The breadcrumbs are automatically included in log metadata
 * via the Context facade.
 */
class Breadcrumb
{
    /**
     * Push a breadcrumb entry to the context stack.
     */
    public static function add(string $label, ?string $detail = null): void
    {
        $entry = $detail !== null ? "{$label}: {$detail}" : $label;

        Context::push('breadcrumbs', $entry);
    }

    /**
     * Get all current breadcrumbs.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return Context::get('breadcrumbs') ?? [];
    }

    /**
     * Clear all breadcrumbs.
     */
    public static function clear(): void
    {
        Context::forget('breadcrumbs');
    }
}
