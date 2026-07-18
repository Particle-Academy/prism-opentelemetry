<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Unit;

use Illuminate\Contracts\Support\Arrayable;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\Support\GenAiAttributes;
use Prism\OpenTelemetry\Support\OpenInferenceAttributes;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
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

it('maps user and session ids for Phoenix grouping', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = new TelemetryContext('t-session', TelemetryOperation::Text, 'openai', 'gpt-4o', microtime(true), userId: 'user-7', sessionId: 'session-9');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 1.0));

    $attributes = $exporter->getSpans()[0]->getAttributes();
    expect($attributes->get(OpenInferenceAttributes::USER_ID))->toBe('user-7')
        ->and($attributes->get(OpenInferenceAttributes::SESSION_ID))->toBe('session-9');
});

it('nests step and tool spans under the root via trace id', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('t2', TelemetryOperation::Text, 'anthropic', 'claude');

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onStepCompleted(new StepCompleted($ctx->withStep(0), FinishReason::ToolCalls, new Usage(3, 1)));
    $sub->onToolInvoked(new ToolInvoked($ctx->withStep(0)->withTool(0), 'weather', 'c1', 12.5));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 40.0, FinishReason::Stop, new Usage(8, 4)));

    $spans = collect($exporter->getSpans())->keyBy(fn ($span): string => $span->getName());

    $root = $spans->get('chat claude');
    $step = $spans->get('step 0');
    $tool = $spans->get('execute_tool weather');

    expect($root)->not->toBeNull();
    expect($step)->not->toBeNull();
    expect($tool)->not->toBeNull();

    // steps parent to root; tools parent to their owning step.
    expect($step->getParentSpanId())->toBe($root->getSpanId());
    expect($tool->getParentSpanId())->toBe($step->getSpanId());
    expect($step->getTraceId())->toBe($root->getTraceId());

    expect($step->getAttributes()->get(GenAiAttributes::STEP_INDEX))->toBe(0);
    expect($tool->getAttributes()->get(GenAiAttributes::TOOL_NAME))->toBe('weather');
    expect($tool->getAttributes()->get(GenAiAttributes::TOOL_CALL_ID))->toBe('c1');
    expect($tool->getAttributes()->get(GenAiAttributes::TOOL_INDEX))->toBe(0);
});

it('maps captured input output messages and tool content to OpenInference attributes', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('t-content');
    $request = new class
    {
        public function prompt(): string
        {
            return 'What is the weather?';
        }

        public function systemPrompts(): array
        {
            return [new SystemMessage('Be concise.')];
        }

        public function messages(): array
        {
            return [new UserMessage('Weather in Chicago'), new class
            {
                private string $secret = 'must-not-leak';
            }];
        }
    };
    $step = new class implements Arrayable
    {
        public function toArray(): array
        {
            return ['system_prompts' => [['type' => 'system', 'content' => 'Be concise.']], 'messages' => [['type' => 'user', 'content' => 'Weather in Chicago']], 'text' => 'It is sunny.'];
        }
    };
    $response = new class
    {
        public string $text = 'It is sunny.';
    };

    $sub->onGenerationStarted(new GenerationStarted($ctx, $request));
    $sub->onToolInvoked(new ToolInvoked($ctx->withStep(0)->withTool(0), 'weather', 'c1', 1.0, new ToolCall('c1', 'weather', ['city' => 'Chicago']), new ToolResult('c1', 'weather', ['city' => 'Chicago'], ['temperature' => 72])));
    $sub->onStepCompleted(new StepCompleted($ctx->withStep(0), FinishReason::Stop, new Usage(4, 3), $step));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 5.0, FinishReason::Stop, new Usage(4, 3), $response));

    $spans = collect($exporter->getSpans())->keyBy(fn ($span): string => $span->getName());
    $root = $spans->get('chat gpt-4o')->getAttributes();
    $llm = $spans->get('step 0')->getAttributes();
    $tool = $spans->get('execute_tool weather')->getAttributes();

    expect($root->get(OpenInferenceAttributes::INPUT_VALUE))->toContain('Weather in Chicago')
        ->and($root->get(OpenInferenceAttributes::INPUT_VALUE))->not->toContain('must-not-leak')
        ->and($root->get(OpenInferenceAttributes::OUTPUT_VALUE))->toContain('It is sunny.')
        ->and($llm->get(OpenInferenceAttributes::LLM_MODEL_NAME))->toBe('gpt-4o')
        ->and($llm->get(OpenInferenceAttributes::INPUT_MESSAGES.'.1.message.content'))->toBe('Weather in Chicago')
        ->and($llm->get(OpenInferenceAttributes::OUTPUT_MESSAGES.'.0.message.content'))->toBe('It is sunny.')
        ->and($tool->get(OpenInferenceAttributes::TOOL_PARAMETERS))->toContain('Chicago')
        ->and($tool->get(OpenInferenceAttributes::OUTPUT_VALUE))->toContain('temperature');
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

it('maps embedding and image semantic input output without exporting vectors or base64', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $embeddingContext = context('semantic-embedding', TelemetryOperation::Embeddings, 'openai', 'text-embedding-3-small');
    $embeddingRequest = new class
    {
        public function inputs(): array
        {
            return ['semantic input'];
        }
    };
    $embeddingResponse = new class
    {
        public array $embeddings;

        public function __construct()
        {
            $this->embeddings = [(object) ['embedding' => [0.1, 0.2, 0.3]]];
        }
    };

    $sub->onGenerationStarted(new GenerationStarted($embeddingContext, $embeddingRequest));
    $sub->onGenerationCompleted(new GenerationCompleted($embeddingContext, 1.0, response: $embeddingResponse));

    $attributes = $exporter->getSpans()[0]->getAttributes();
    expect($attributes->get(OpenInferenceAttributes::INPUT_VALUE))->toContain('semantic input')
        ->and($attributes->get(OpenInferenceAttributes::OUTPUT_VALUE))->toBe('{"count":1,"dimensions":3}')
        ->and($attributes->get(OpenInferenceAttributes::OUTPUT_VALUE))->not->toContain('0.1');

    $imageContext = context('semantic-image', TelemetryOperation::Image, 'openai', 'dall-e-3');
    $imageRequest = new class
    {
        public function prompt(): string
        {
            return 'A cyan prism';
        }
    };
    $imageResponse = new class
    {
        public array $images;

        public function __construct()
        {
            $this->images = [new class implements Arrayable
            {
                public function toArray(): array
                {
                    return ['url' => 'https://example.test/prism.png?sig=secret-signed-token#fragment', 'base64' => 'secret-large-payload', 'mime_type' => 'image/png', 'revised_prompt' => 'A cyan glass prism'];
                }
            }];
        }
    };

    $sub->onGenerationStarted(new GenerationStarted($imageContext, $imageRequest));
    $sub->onGenerationCompleted(new GenerationCompleted($imageContext, 1.0, response: $imageResponse));

    $imageAttributes = $exporter->getSpans()[1]->getAttributes();
    expect($imageAttributes->get(OpenInferenceAttributes::INPUT_VALUE))->toContain('A cyan prism')
        ->and($imageAttributes->get(OpenInferenceAttributes::OUTPUT_VALUE))->toContain('prism.png')
        ->and($imageAttributes->get(OpenInferenceAttributes::OUTPUT_VALUE))->not->toContain('secret-large-payload')
        ->and($imageAttributes->get(OpenInferenceAttributes::OUTPUT_VALUE))->not->toContain('secret-signed-token')
        ->and($imageAttributes->get(OpenInferenceAttributes::OUTPUT_VALUE))->not->toContain('fragment');
});

it('caps captured content to the configured max length', function (): void {
    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new SimpleSpanProcessor($exporter));
    $sub = new TelemetrySubscriber($provider->getTracer('test'), new SpanStore, true, maxContentLength: 32);

    $ctx = context('cap');
    $response = new \stdClass;
    $response->text = str_repeat('A', 5_000);

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 1.0, FinishReason::Stop, new Usage(1, 1), $response));

    $out = collect($exporter->getSpans())
        ->firstWhere(fn ($span): bool => $span->getName() === 'chat gpt-4o')
        ->getAttributes()->get(OpenInferenceAttributes::OUTPUT_VALUE);

    expect($out)->toEndWith('…[truncated]')
        ->and(strlen((string) $out))->toBeLessThan(60); // 32 bytes + marker, not 5000
});

it('never throws into the app when captured content has malformed UTF-8', function (): void {
    [$sub, $exporter] = subscriberHarness();
    $ctx = context('bad-utf8');

    $response = new \stdClass;
    $response->structured = ['v' => "\xB1\x31"]; // invalid UTF-8 → json() must not throw

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationCompleted(new GenerationCompleted($ctx, 1.0, FinishReason::Stop, new Usage(1, 1), $response));

    $root = collect($exporter->getSpans())->firstWhere(fn ($span): bool => $span->getName() === 'chat gpt-4o');

    expect($root)->not->toBeNull()
        ->and($root->getAttributes()->get(OpenInferenceAttributes::OUTPUT_VALUE))->toBeString();
});
