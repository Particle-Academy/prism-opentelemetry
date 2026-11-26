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
use Throwable;

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
        ->and(exportedText($span))->not->toContain(trim((string) json_encode(__FILE__, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), '"'))
        ->and(exportedText($span))->not->toContain(basename(__FILE__));
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

it('follows a capture switch changed at runtime, as core does', function (): void {
    // Booted with capture on, then turned off for one request. Core reads the
    // key on every event, so the bridge must too.
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', true);

    $exporter = new InMemoryExporter;
    app()->instance(TracerInterface::class, (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('runtime'));
    app()->instance(SpanStore::class, new SpanStore);
    app()->forgetInstance(TelemetrySubscriber::class);
    app(Dispatcher::class)->subscribe(TelemetrySubscriber::class);

    config()->set('prism.telemetry.capture_content', false);

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
    Telemetry::failed($context, new RuntimeException(EXCEPTION_MARKER));

    expect(exportedText($exporter->getSpans()[0]))->not->toContain(EXCEPTION_MARKER);
});

it('cuts a captured message and stack trace to content_max_length', function (): void {
    $exporter = new InMemoryExporter;
    $subscriber = new TelemetrySubscriber(
        (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('bounded'),
        new SpanStore,
        maxContentLength: 40,
        captureContent: true,
    );
    config()->set('prism.telemetry.enabled', true);
    app(Dispatcher::class)->subscribe($subscriber);

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
    Telemetry::failed($context, new RuntimeException(str_repeat('x', 5000)));

    $span = $exporter->getSpans()[0];
    $attributes = $span->getEvents()[0]->getAttributes()->toArray();

    expect(strlen((string) $span->getStatus()->getDescription()))->toBeLessThan(60)
        ->and(strlen($attributes['exception.message']))->toBeLessThan(60)
        ->and(strlen($attributes['exception.stacktrace']))->toBeLessThan(60);
});

it('writes no frame arguments into a captured stack trace', function (): void {
    // getTraceAsString() includes arguments unless zend.exception_ignore_args
    // is on; the bridge builds its own trace so that setting does not matter.
    $exporter = new InMemoryExporter;
    $subscriber = new TelemetrySubscriber(
        (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('frames'),
        new SpanStore,
        captureContent: true,
    );
    config()->set('prism.telemetry.enabled', true);
    app(Dispatcher::class)->subscribe($subscriber);

    $thrower = function (string $argument): never {
        throw new RuntimeException('a failure');
    };

    try {
        $thrower('ARGUMENT-'.EXCEPTION_MARKER);
    } catch (RuntimeException $exception) {
        $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
        Telemetry::failed($context, $exception);
    }

    $stacktrace = $exporter->getSpans()[0]->getEvents()[0]->getAttributes()->toArray()['exception.stacktrace'];

    expect($stacktrace)->toContain('RuntimeException: a failure')
        ->and($stacktrace)->toContain('ExceptionContentGateTest.php')
        ->and($stacktrace)->not->toContain(EXCEPTION_MARKER);
});

it('follows the switch turned ON at runtime too', function (): void {
    config()->set('prism.telemetry.enabled', true);
    config()->set('prism.telemetry.capture_content', false);

    $exporter = new InMemoryExporter;
    app()->instance(TracerInterface::class, (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('runtime-on'));
    app()->instance(SpanStore::class, new SpanStore);
    app()->forgetInstance(TelemetrySubscriber::class);
    app(Dispatcher::class)->subscribe(TelemetrySubscriber::class);

    config()->set('prism.telemetry.capture_content', true);

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
    Telemetry::failed($context, new RuntimeException(EXCEPTION_MARKER));

    expect(exportedText($exporter->getSpans()[0]))->toContain(EXCEPTION_MARKER);
});

it('lists at most sixteen exceptions of a chain in a captured stack trace', function (): void {
    $exporter = new InMemoryExporter;
    $subscriber = new TelemetrySubscriber(
        (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('chain'),
        new SpanStore,
        maxContentLength: 0,
        captureContent: true,
    );
    config()->set('prism.telemetry.enabled', true);
    app(Dispatcher::class)->subscribe($subscriber);

    $exception = new RuntimeException('link 0');
    for ($i = 1; $i <= 20; $i++) {
        $exception = new RuntimeException("link {$i}", 0, $exception);
    }

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-5-5', 'a prompt');
    Telemetry::failed($context, $exception);

    $stacktrace = $exporter->getSpans()[0]->getEvents()[0]->getAttributes()->toArray()['exception.stacktrace'];

    expect(substr_count($stacktrace, 'Caused by: '))->toBe(15)
        ->and($stacktrace)->toContain('link 20')
        ->and($stacktrace)->not->toContain('link 4:');
});

it('stops building a captured stack trace once it passes the limit', function (): void {
    // The final cut would hide a missing guard, so the build is measured
    // directly: a deep trace and a long chain, against a small limit.
    $subscriber = new class((new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter)))->getTracer('raw'), new SpanStore, maxContentLength: 64) extends TelemetrySubscriber
    {
        public function rawStackTrace(Throwable $exception): string
        {
            return $this->stackTrace($exception);
        }
    };

    $deep = function (int $n) use (&$deep): never {
        if ($n === 0) {
            throw new RuntimeException('bottom');
        }
        $deep($n - 1);
    };

    try {
        $deep(200);
    } catch (RuntimeException $exception) {
        $chained = new RuntimeException(str_repeat('m', 500), 0, new RuntimeException(str_repeat('p', 500), 0, $exception));
    }

    foreach ([$exception, $chained] as $thrown) {
        $raw = $subscriber->rawStackTrace($thrown);
        $lines = explode("\n", $raw);
        $longest = max(array_map('strlen', $lines));

        // At most the limit plus the one line that crossed it.
        expect(strlen($raw))->toBeLessThanOrEqual(64 + $longest + 1)
            ->and(count($lines))->toBeLessThan(10);
    }
});
