<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Skeylup\OwlogsAgent\OwlogsAgentServiceProvider;

abstract class AutoRegisterDisabledTestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            OwlogsAgentServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('owlogs.auto_register_stack', false);
    }
}
