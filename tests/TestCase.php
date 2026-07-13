<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Prism\OpenTelemetry\PrismOpenTelemetryServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PrismOpenTelemetryServiceProvider::class];
    }
}
