<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;

class PrismOpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/prism-opentelemetry.php', 'prism-opentelemetry');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/prism-opentelemetry.php' => $this->app->configPath('prism-opentelemetry.php'),
        ], 'prism-opentelemetry-config');

        if (config('prism-opentelemetry.enabled', true) === false) {
            return;
        }

        // The OpenTelemetry API must be present for the bridge to do anything.
        if (! class_exists(Globals::class)) {
            return;
        }

        // Subscriber is wired in a subsequent commit; guard so the scaffold boots
        // cleanly on its own.
        $subscriber = 'Prism\\OpenTelemetry\\TelemetrySubscriber';

        if (class_exists($subscriber)) {
            $this->app->make(Dispatcher::class)->subscribe($subscriber);
        }
    }
}
