<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Flushing;

/**
 * Detects whether the current PHP process is serving requests inside a
 * Laravel Octane worker (swoole / roadrunner / frankenphp) or a short-lived
 * runtime (php-fpm via Herd / Valet, artisan, queue:work).
 *
 * Checks are intentionally defensive: we look at runtime markers set by
 * Octane itself, not composer-level signals like `app()->bound('octane')`
 * — which is true the moment the package is installed, even under FPM.
 */
final class RuntimeDetector
{
    public static function isOctane(): bool
    {
        if (isset($_SERVER['LARAVEL_OCTANE']) && $_SERVER['LARAVEL_OCTANE'] !== '' && $_SERVER['LARAVEL_OCTANE'] !== '0') {
            return true;
        }

        if (defined('LARAVEL_OCTANE')) {
            return true;
        }

        return false;
    }

    public static function octaneServer(): ?string
    {
        $server = $_SERVER['OCTANE_SERVER'] ?? null;

        if (! is_string($server) || $server === '') {
            $server = function_exists('config') ? config('octane.server') : null;
        }

        return is_string($server) && $server !== '' ? $server : null;
    }
}
