<?php

declare(strict_types=1);

namespace Skeylup\OwlogsAgent\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Skeylup\OwlogsAgent\OwlogsAgentServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            OwlogsAgentServiceProvider::class,
        ];
    }
}
