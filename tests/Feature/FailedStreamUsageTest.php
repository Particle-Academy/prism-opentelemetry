<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\Support\GenAiAttributes;
use Prism\OpenTelemetry\Support\OpenInferenceAttributes;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

const ERROR_EVENT = 'Prism\\Prism\\Events\\Telemetry\\GenerationErrored';
const FAILURE_USAGE = 'Prism\\Prism\\ValueObjects\\StreamFailureUsage';

function failedStreamBridge(): array
{
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
    $store = new SpanStore;

    return [new TelemetrySubscriber($provider->getTracer('failure-test'), $store), $store, $exporter];
}

function failedStreamContext(): TelemetryContext
{
    return new TelemetryContext('failed-stream', TelemetryOperation::Stream, 'anthropic', 'offline-model', microtime(true));
}

beforeEach(function (): void {
    config(['prism.telemetry.enabled' => true, 'prism.telemetry.capture_content' => false]);
});

it('subscribes to the real optional event exactly when core supplies it', function (): void {
    $events = $this->app->make(Dispatcher::class);
    expect($events->hasListeners(ERROR_EVENT))->toBe(class_exists(ERROR_EVENT));

    [$subscriber, $store, $exporter] = failedStreamBridge();
    $subscriber->onGenerationErrored(new \stdClass);
    expect($exporter->getSpans())->toBeEmpty()->and($store->has('failed-stream'))->toBeFalse();
});

it('closes an event-signalled failure once and exports only reported provisional numbers', function (): void {
    if (! class_exists(ERROR_EVENT)) {
        $this->markTestSkipped('Requires the native core 0.146 event.');
    }
    [$subscriber, $store, $exporter] = failedStreamBridge();
    $context = failedStreamContext();
    $class = FAILURE_USAGE;
    $failure = new $class(new Usage(11, 3, cacheWriteInputTokens: 5, cacheReadInputTokens: 7, thoughtTokens: 2, cost: 0.0, cacheWrite5mInputTokens: 2, cacheWrite1hInputTokens: 3), 2, ['prompt_tokens', 'completion_tokens', 'cache_write_input_tokens', 'cache_read_input_tokens', 'thought_tokens', 'cost', 'cache_write_5m_input_tokens', 'cache_write_1h_input_tokens']);
    $class = ERROR_EVENT;
    $event = new $class($context, 10.0, $failure);
    $subscriber->onGenerationStarted(new GenerationStarted($context));
    $subscriber->onGenerationErrored($event);
    $subscriber->onGenerationErrored($event);
    $subscriber->onGenerationFailed(new GenerationFailed($context, 10.0, new RuntimeException('later original failure')));
    $spans = $exporter->getSpans();
    expect($spans)->toHaveCount(1)->and($store->has($context->traceId))->toBeFalse();
    $attributes = $spans[0]->getAttributes();
    expect($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans[0]->getEvents())->toBeEmpty()
        ->and($attributes->get(GenAiAttributes::USAGE_INPUT_TOKENS))->toBe(23)
        ->and($attributes->get(GenAiAttributes::USAGE_OUTPUT_TOKENS))->toBe(3)
        ->and($attributes->get(OpenInferenceAttributes::TOKEN_COUNT_TOTAL))->toBe(26)
        ->and($attributes->get(GenAiAttributes::USAGE_COST))->toBe(0.0)
        ->and($attributes->get('prism.usage.cache_write_5m_input_tokens'))->toBe(2)
        ->and($attributes->get('prism.usage.cache_write_1h_input_tokens'))->toBe(3)
        ->and($attributes->get('prism.usage.status'))->toBe('provisional')
        ->and($attributes->get('prism.usage.scope'))->toBe('attempt')
        ->and($attributes->get('prism.usage.includes_completed_steps'))->toBeFalse()
        ->and($attributes->get('prism.usage.step_index'))->toBe(2);
});

it('keeps missing counts absent while retaining an explicit reported zero', function (): void {
    if (! class_exists(ERROR_EVENT)) {
        $this->markTestSkipped('Requires the native core 0.146 event.');
    }
    [$subscriber, , $exporter] = failedStreamBridge();
    $context = failedStreamContext();
    $class = FAILURE_USAGE;
    $failure = new $class(new Usage(0, 0), 0, ['completion_tokens']);
    $class = ERROR_EVENT;
    $subscriber->onGenerationStarted(new GenerationStarted($context));
    $subscriber->onGenerationErrored(new $class($context, 1.0, $failure));
    $attributes = $exporter->getSpans()[0]->getAttributes();
    expect($attributes->has(GenAiAttributes::USAGE_INPUT_TOKENS))->toBeFalse()
        ->and($attributes->has(OpenInferenceAttributes::TOKEN_COUNT_TOTAL))->toBeFalse()
        ->and($attributes->has(GenAiAttributes::USAGE_COST))->toBeFalse()
        ->and($attributes->get(GenAiAttributes::USAGE_OUTPUT_TOKENS))->toBe(0);
});

it('maps usage on the native thrown-failure event without requiring the new event class in old installs', function (): void {
    if (! class_exists(FAILURE_USAGE)) {
        $this->markTestSkipped('Requires native core 0.146 failure usage.');
    }
    [$subscriber, , $exporter] = failedStreamBridge();
    $context = failedStreamContext();
    $class = FAILURE_USAGE;
    $failure = new $class(new Usage(4, 2), 1, ['prompt_tokens', 'completion_tokens']);
    $subscriber->onGenerationStarted(new GenerationStarted($context));
    $subscriber->onGenerationFailed(new GenerationFailed($context, 1.0, new RuntimeException('original failure'), $failure));
    expect($exporter->getSpans()[0]->getAttributes()->get(GenAiAttributes::USAGE_INPUT_TOKENS))->toBe(4)
        ->and($exporter->getSpans()[0]->getAttributes()->get('prism.usage.status'))->toBe('provisional');
});

it('closes a span through native core streaming telemetry with capture disabled', function (): void {
    if (! class_exists(ERROR_EVENT)) {
        $this->markTestSkipped('Requires the native core 0.146 event.');
    }
    [$subscriber, $store, $exporter] = failedStreamBridge();
    $subscriber->subscribe($this->app->make(Dispatcher::class));
    $context = failedStreamContext();
    $class = FAILURE_USAGE;
    $failure = new $class(new Usage(9, 0), 0, ['prompt_tokens']);
    event(new GenerationStarted($context));
    $stream = (function () use ($failure): \Generator {
        yield new ErrorEvent('owned', 0, 'provider_error', 'PRIVATE_PROVIDER_CONTENT', false, failureUsage: $failure);
    })();
    iterator_to_array(Telemetry::instrumentStream($context, $stream, null));
    $spans = $exporter->getSpans();
    expect($spans)->toHaveCount(1)->and($store->has($context->traceId))->toBeFalse()
        ->and($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($spans[0]->getAttributes()->get(GenAiAttributes::USAGE_INPUT_TOKENS))->toBe(9)
        ->and(json_encode($spans[0]->getAttributes()->toArray()))->not->toContain('PRIVATE_PROVIDER_CONTENT')
        ->and($spans[0]->getAttributes()->has(OpenInferenceAttributes::OUTPUT_VALUE))->toBeFalse()
        ->and($spans[0]->getEvents())->toBeEmpty();
});
