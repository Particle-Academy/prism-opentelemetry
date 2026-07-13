<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Unit;

use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\Support\GenAiAttributes;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

/**
 * @return array{0: TelemetrySubscriber, 1: InMemoryExporter}
 */
function subscriberHarness(bool $recordExceptions = true): array
{
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new SimpleSpanProcessor($exporter));

    return [new TelemetrySubscriber($provider->getTracer('test'), new SpanStore, $recordExceptions), $exporter];
}

function context(string $traceId, TelemetryOperation $operation = TelemetryOperation::Text, string $provider = 'openai', string $model = 'gpt-4o'): TelemetryContext
{
    return new TelemetryContext($traceId, $operation, $provider, $model, microtime(true));
}

it('builds a root span with GenAI attributes', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('t1');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 20.0, FinishReason::Stop, new Usage(10, 5)));

    $spans = $exporter->getSpans();
    expect($spans)->toHaveCount(1);

    $root = $spans[0];
    expect($root->getName())->toBe('chat gpt-4o');

    $attrs = $root->getAttributes();
    expect($attrs->get(GenAiAttributes::SYSTEM))->toBe('openai');
    expect($attrs->get(GenAiAttributes::OPERATION_NAME))->toBe('chat');
    expect($attrs->get(GenAiAttributes::REQUEST_MODEL))->toBe('gpt-4o');
    expect($attrs->get(GenAiAttributes::USAGE_INPUT_TOKENS))->toBe(10);
    expect($attrs->get(GenAiAttributes::USAGE_OUTPUT_TOKENS))->toBe(5);
    expect($attrs->get(GenAiAttributes::RESPONSE_FINISH_REASONS))->toBe(['Stop']);
});

it('records the cost attribute when present', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('t-cost');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 5.0, FinishReason::Stop, new Usage(1, 1, cost: 0.0025)));

    expect($exporter->getSpans()[0]->getAttributes()->get(GenAiAttributes::USAGE_COST))->toBe(0.0025);
});

it('nests step and tool spans under the root via trace id', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('t2', TelemetryOperation::Text, 'anthropic', 'claude');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onStepCompleted(new StepCompleted($ctx->withStep(0), FinishReason::ToolCalls, new Usage(3, 1)));
    $sub->onToolInvoked(new ToolInvoked($ctx->withTool(0), 'weather', 'c1', 12.5));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 40.0, FinishReason::Stop, new Usage(8, 4)));

    $spans = collect($exporter->getSpans())->keyBy(fn ($span): string => $span->getName());

    $root = $spans->get('chat claude');
    $step = $spans->get('step 0');
    $tool = $spans->get('execute_tool weather');

    expect($root)->not->toBeNull();
    expect($step)->not->toBeNull();
    expect($tool)->not->toBeNull();

    // children are parented to the root, within the same trace
    expect($step->getParentSpanId())->toBe($root->getSpanId());
    expect($tool->getParentSpanId())->toBe($root->getSpanId());
    expect($step->getTraceId())->toBe($root->getTraceId());

    expect($step->getAttributes()->get(GenAiAttributes::STEP_INDEX))->toBe(0);
    expect($tool->getAttributes()->get(GenAiAttributes::TOOL_NAME))->toBe('weather');
    expect($tool->getAttributes()->get(GenAiAttributes::TOOL_CALL_ID))->toBe('c1');
    expect($tool->getAttributes()->get(GenAiAttributes::TOOL_INDEX))->toBe(0);
});

it('gives the tool span a duration derived from durationMs', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('t-dur');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onToolInvoked(new ToolInvoked($ctx->withTool(0), 'slow', 'c1', 50.0));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 60.0));

    $tool = collect($exporter->getSpans())->firstWhere(fn ($span): bool => $span->getName() === 'execute_tool slow');
    $durationMs = ($tool->getEndEpochNanos() - $tool->getStartEpochNanos()) / 1_000_000;

    expect($durationMs)->toEqualWithDelta(50.0, 1.0);
});

it('marks the root span as error on failure and records the exception', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('t3');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationFailed(new GenerationFailed($ctx, 5.0, new RuntimeException('boom')));

    $spans = $exporter->getSpans();
    expect($spans)->toHaveCount(1);
    expect($spans[0]->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
    expect($spans[0]->getStatus()->getDescription())->toBe('boom');
    expect($spans[0]->getEvents())->not->toBeEmpty(); // exception recorded as a span event
});

it('omits the exception event when record_exceptions is disabled', function (): void {
    [$sub, $exporter] = subscriberHarness(recordExceptions: false);
    $ctx = context('t4');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationFailed(new GenerationFailed($ctx, 1.0, new RuntimeException('nope')));

    $span = $exporter->getSpans()[0];
    expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR);
    expect($span->getEvents())->toBeEmpty();
});

it('ignores step/tool/completion events for an unknown trace id', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('never-started');

    $sub->onStepCompleted(new StepCompleted($ctx->withStep(0), null, null));
    $sub->onToolInvoked(new ToolInvoked($ctx->withTool(0), 't', 'c1', 1.0));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 1.0));

    expect($exporter->getSpans())->toBeEmpty();
});

it('maps embeddings and image operations to their span names', function (): void {
    [$sub, $exporter] = subscriberHarness();

    $sub->onGenerationStarted(new GenerationStarted(context('e1', TelemetryOperation::Embeddings, 'openai', 'text-embedding-3')));
    $sub->onGenerationCompleted(new GenerationCompleted(context('e1', TelemetryOperation::Embeddings, 'openai', 'text-embedding-3'), 3.0));

    expect($exporter->getSpans()[0]->getName())->toBe('embeddings text-embedding-3');
});
