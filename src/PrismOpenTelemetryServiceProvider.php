<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\TracerInterface;

class PrismOpenTelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/prism-opentelemetry.php', 'prism-opentelemetry');

        $this->app->singleton(SpanStore::class);

        $this->app->singleton(TracerInterface::class, fn (): TracerInterface => Globals::tracerProvider()->getTracer(
            (string) config('prism-opentelemetry.tracer_name', 'prism')
        ));

        $this->app->singleton(TelemetrySubscriber::class, fn ($app): TelemetrySubscriber => new TelemetrySubscriber(
            $app->make(TracerInterface::class),
            $app->make(SpanStore::class),
            (bool) config('prism-opentelemetry.record_exceptions', true),
            (int) config('prism-opentelemetry.content_max_length', 65_536),
        ));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/prism-opentelemetry.php' => $this->app->configPath('prism-opentelemetry.php'),
        ], 'prism-opentelemetry-config');

        if (config('prism-opentelemetry.enabled', true) === false) {
            return;
        }

        // The OpenTelemetry API must be installed for the bridge to do anything.
        if (! class_exists(Globals::class)) {
            return;
        }

        $this->app->make(Dispatcher::class)->subscribe(TelemetrySubscriber::class);
    }
}
