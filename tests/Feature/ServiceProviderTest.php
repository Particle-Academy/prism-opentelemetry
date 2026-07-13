<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use OpenTelemetry\API\Trace\TracerInterface;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;

it('resolves the bridge bindings', function (): void {
    expect($this->app->make(SpanStore::class))->toBeInstanceOf(SpanStore::class);
    expect($this->app->make(TracerInterface::class))->toBeInstanceOf(TracerInterface::class);
    expect($this->app->make(TelemetrySubscriber::class))->toBeInstanceOf(TelemetrySubscriber::class);
});

it('subscribes the telemetry listener to every Prism telemetry event', function (): void {
    $events = $this->app->make(Dispatcher::class);

    foreach ([
        GenerationStarted::class,
        StepCompleted::class,
        ToolInvoked::class,
        GenerationCompleted::class,
        GenerationFailed::class,
    ] as $event) {
        expect($events->hasListeners($event))->toBeTrue();
    }
});
