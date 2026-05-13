<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Skeylup\OwlogsAgent\OwlogsAgentServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = [];

        // Mount Livewire FIRST when available so its boot() runs before
        // ours — this mirrors the auto-discovered order in real apps and
        // surfaces the boot/register ordering bug where a hook registered
        // in our boot() silently lands after Livewire's ComponentHookRegistry
        // has already snapshotted the registered hooks.
        if (class_exists(\Livewire\LivewireServiceProvider::class)) {
            $providers[] = \Livewire\LivewireServiceProvider::class;
        }

        $providers[] = OwlogsAgentServiceProvider::class;

        return $providers;
    }
}
