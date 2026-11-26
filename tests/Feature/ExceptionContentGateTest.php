<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Feature;

use Illuminate\Contracts\Events\Dispatcher;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Telemetry;
use RuntimeException;

/**
 * An exception message is content.
 *
 * A failed database write repeats the row it tried to insert, a provider error
 * can quote the prompt, a validation error quotes the input. Core hands the
 * bridge the exception itself whatever `prism.telemetry.capture_content` says,
 * so the bridge is the only place that can keep its text off the span.
 *
 * These tests go through the shipped wiring: the service provider builds the
 * subscriber from config, and core's `Telemetry` dispatches the real events.
 */
const EXCEPTION_MARKER = 'CONTENT-MARKER-0f1e';

/** @return list<ImmutableSpan> */
function failWithMarker(bool $capture, TelemetryOperation $operation = TelemetryOperation::Text): array
{
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', $capture);

    $exporter = new InMemoryExporter;
    app()->instance(TracerInterface::class, (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('exception-gate'));
    app()->instance(SpanStore::class, new SpanStore);
    app()->forgetInstance(TelemetrySubscriber::class);
    app(Dispatcher::class)->subscribe(TelemetrySubscriber::class);

    $previous = new RuntimeException('previous: tool result '.EXCEPTION_MARKER);
    $exception = new RuntimeException(
        "SQLSTATE[22001]: insert into tool_runs (arguments, result) values ('{\"q\":\"".EXCEPTION_MARKER."\"}')",
        0,
        $previous,
    );

    $context = Telemetry::start($operation, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
    Telemetry::failed($context, $exception);

    /** @var list<ImmutableSpan> $spans */
    $spans = $exporter->getSpans();

    return $spans;
}

/** Everything a span exports that could carry text, as one string. */
function exportedText(ImmutableSpan $span): string
{
    $events = array_map(fn ($event): array => [
        'name' => $event->getName(),
        'attributes' => $event->getAttributes()->toArray(),
    ], $span->getEvents());

    return (string) json_encode([
        'name' => $span->getName(),
        'status' => $span->getStatus()->getDescription(),
        'attributes' => $span->getAttributes()->toArray(),
        'events' => $events,
        'links' => count($span->getLinks()),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

it('keeps the exception message off every span when content capture is off', function (TelemetryOperation $operation): void {
    $spans = failWithMarker(false, $operation);

    expect($spans)->toHaveCount(1);

    foreach ($spans as $span) {
        expect(exportedText($span))->not->toContain(EXCEPTION_MARKER);
    }

    $span = $spans[0];
    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
        ->and($span->getStatus()->getDescription())->toBe('The generation failed.');

    // The event stays, so a reader can still tell WHAT failed.
    expect($span->getEvents())->toHaveCount(1);
    $event = $span->getEvents()[0];
    expect($event->getName())->toBe('exception')
        ->and($event->getAttributes()->toArray())->toBe(['exception.type' => RuntimeException::class]);
})->with([
    'text' => [TelemetryOperation::Text],
    'structured' => [TelemetryOperation::Structured],
    'embeddings' => [TelemetryOperation::Embeddings],
    'image' => [TelemetryOperation::Image],
    'stream' => [TelemetryOperation::Stream],
]);

it('records the message, the stack trace and the previous exception when capture is on', function (): void {
    $spans = failWithMarker(true);

    $span = $spans[0];
    expect($span->getStatus()->getDescription())->toContain(EXCEPTION_MARKER);

    $attributes = $span->getEvents()[0]->getAttributes()->toArray();
    expect($attributes['exception.type'])->toBe(RuntimeException::class)
        ->and($attributes['exception.message'])->toContain(EXCEPTION_MARKER)
        ->and($attributes['exception.stacktrace'])->toContain('Caused by:')
        ->and($attributes['exception.stacktrace'])->toContain('previous: tool result '.EXCEPTION_MARKER);
});

it('records no event at all when record_exceptions is off, whatever capture says', function (bool $capture): void {
    config()->set('prism-opentelemetry.record_exceptions', false);

    $span = failWithMarker($capture)[0];

    expect($span->getEvents())->toBe([])
        ->and($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
})->with(['capture off' => [false], 'capture on' => [true]]);

it('names an anonymous exception class without the file path PHP appends to it', function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);

    $exporter = new InMemoryExporter;
    $subscriber = new TelemetrySubscriber((new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('anon'), new SpanStore);
    app(Dispatcher::class)->subscribe($subscriber);

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
    Telemetry::failed($context, new class(EXCEPTION_MARKER) extends RuntimeException {});

    $span = $exporter->getSpans()[0];
    $type = $span->getEvents()[0]->getAttributes()->toArray()['exception.type'];

    expect($type)->toBe('RuntimeException@anonymous')
        ->and(exportedText($span))->not->toContain(EXCEPTION_MARKER)
        ->and(exportedText($span))->not->toContain(__FILE__);
});

it('defaults to capture OFF when the subscriber is built by hand', function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', true);

    $exporter = new InMemoryExporter;
    $subscriber = new TelemetrySubscriber((new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('by-hand'), new SpanStore);
    app(Dispatcher::class)->subscribe($subscriber);

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
    Telemetry::failed($context, new RuntimeException(EXCEPTION_MARKER));

    expect(exportedText($exporter->getSpans()[0]))->not->toContain(EXCEPTION_MARKER);
});
