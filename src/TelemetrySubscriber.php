<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Support\Arrayable;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ContextInterface;
use Prism\OpenTelemetry\Support\GenAiAttributes;
use Prism\OpenTelemetry\Support\OpenInferenceAttributes;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\ValueObjects\Usage;

/**
 * Builds GenAI-convention OpenTelemetry spans from Prism's telemetry events.
 *
 * One root span per generation; child spans per step and per tool call, parented
 * deterministically off the stored root context via the trace id (never ambient
 * scope, which does not survive Prism's recursive tool loop).
 */
class TelemetrySubscriber
{
    public function __construct(
        protected TracerInterface $tracer,
        protected SpanStore $store,
        protected bool $recordExceptions = true,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(GenerationStarted::class, [$this, 'onGenerationStarted']);
        $events->listen(StepCompleted::class, [$this, 'onStepCompleted']);
        $events->listen(ToolInvoked::class, [$this, 'onToolInvoked']);
        $events->listen(GenerationCompleted::class, [$this, 'onGenerationCompleted']);
        $events->listen(GenerationFailed::class, [$this, 'onGenerationFailed']);
    }

    public function onGenerationStarted(GenerationStarted $event): void
    {
        $context = $event->context;
        $operation = $this->operationName($context->operation);
        $startNanos = $this->nowNanos();

        $span = $this->tracer
            ->spanBuilder($operation.' '.$context->model)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setStartTimestamp($startNanos)
            ->startSpan();

        $span->setAttribute(GenAiAttributes::SYSTEM, $context->provider);
        $span->setAttribute(GenAiAttributes::OPERATION_NAME, $operation);
        $span->setAttribute(GenAiAttributes::REQUEST_MODEL, $context->model);

        // OpenInference — lets Arize Phoenix render the generation as a rich
        // LLM/agent trace rather than an opaque span.
        $span->setAttribute(OpenInferenceAttributes::SPAN_KIND, $this->rootSpanKind($context->operation));
        $span->setAttribute(OpenInferenceAttributes::LLM_MODEL_NAME, $context->model);
        $span->setAttribute(OpenInferenceAttributes::LLM_PROVIDER, $context->provider);
        $span->setAttribute(OpenInferenceAttributes::LLM_SYSTEM, $context->provider);

        if ($context->sessionId !== null) {
            $span->setAttribute(OpenInferenceAttributes::SESSION_ID, $context->sessionId);
        }
        if ($context->userId !== null) {
            $span->setAttribute(OpenInferenceAttributes::USER_ID, $context->userId);
        }

        $this->applyInput($span, $event->request);

        $this->store->start(
            $context->traceId,
            $span,
            $span->storeInContext(Context::getCurrent()),
            $startNanos,
        );
    }

    public function onStepCompleted(StepCompleted $event): void
    {
        $traceId = $event->context->traceId;
        $rootContext = $this->store->context($traceId);

        if ($rootContext === null) {
            return;
        }

        $stepIndex = $event->context->stepIndex ?? 0;
        $tools = $this->store->takeToolsForStep($traceId, $stepIndex);

        $end = $this->nowNanos();
        $start = $this->store->boundaryNanos($traceId) ?? $end;

        // Widen the step window to enclose the tools it ran so the step span
        // visually contains its children in the trace timeline.
        foreach ($tools as $tool) {
            $start = min($start, $tool->startNanos);
            $end = max($end, $tool->endNanos);
        }

        $span = $this->tracer
            ->spanBuilder('step '.$stepIndex)
            ->setParent($rootContext)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setStartTimestamp($start)
            ->startSpan();

        if ($event->context->stepIndex !== null) {
            $span->setAttribute(GenAiAttributes::STEP_INDEX, $event->context->stepIndex);
        }

        // A step is one model turn — an LLM span in OpenInference terms.
        $span->setAttribute(OpenInferenceAttributes::SPAN_KIND, OpenInferenceAttributes::KIND_LLM);
        $span->setAttribute(OpenInferenceAttributes::LLM_MODEL_NAME, $event->context->model);
        $span->setAttribute(OpenInferenceAttributes::LLM_PROVIDER, $event->context->provider);
        $span->setAttribute(OpenInferenceAttributes::LLM_SYSTEM, $event->context->provider);

        $this->applyFinishReason($span, $event->finishReason);
        $this->applyUsage($span, $event->usage);
        $this->applyOpenInferenceUsage($span, $event->usage);
        $this->applyStepContent($span, $event->step);

        // Record the step context so tools that arrive *after* the step (the
        // streaming order) can still parent under it, then materialise any that
        // arrived before (the non-streaming order).
        $stepContext = $span->storeInContext($rootContext);
        $this->store->recordStepContext($traceId, $stepIndex, $stepContext);

        foreach ($tools as $tool) {
            $this->emitToolSpan($tool, $stepContext);
        }

        $span->end($end);

        $this->store->setBoundaryNanos($traceId, $end);
    }

    public function onToolInvoked(ToolInvoked $event): void
    {
        $traceId = $event->context->traceId;

        if (! $this->store->has($traceId)) {
            return;
        }

        $end = $this->nowNanos();
        $start = $end - (int) round($event->durationMs * 1_000_000);

        $tool = new PendingTool(
            name: $event->toolName,
            callId: $event->toolCallId,
            startNanos: $start,
            endNanos: $end,
            stepIndex: $event->context->stepIndex,
            toolIndex: $event->context->toolIndex,
            parameters: $event->toolCall?->arguments(),
            result: $event->toolResult?->result,
        );

        // Streaming completes a step before its tools run, so the step span may
        // already exist — parent the tool under it immediately. Non-streaming
        // replays steps at the end, so buffer until the owning step lands.
        $stepContext = $tool->stepIndex !== null
            ? $this->store->stepContext($traceId, $tool->stepIndex)
            : null;

        if ($stepContext instanceof ContextInterface) {
            $this->emitToolSpan($tool, $stepContext);

            return;
        }

        $this->store->bufferTool($traceId, $tool);
    }

    public function onGenerationCompleted(GenerationCompleted $event): void
    {
        $span = $this->store->span($event->context->traceId);

        if (! $span instanceof SpanInterface) {
            return;
        }

        $this->flushRemainingTools($event->context->traceId);

        $this->applyFinishReason($span, $event->finishReason);
        $this->applyUsage($span, $event->usage);
        $this->applyOpenInferenceUsage($span, $event->usage);
        $this->applyOutput($span, $event->response);

        $span->end($this->nowNanos());

        $this->store->forget($event->context->traceId);
    }

    public function onGenerationFailed(GenerationFailed $event): void
    {
        $span = $this->store->span($event->context->traceId);

        if (! $span instanceof SpanInterface) {
            return;
        }

        $this->flushRemainingTools($event->context->traceId);

        $span->setStatus(StatusCode::STATUS_ERROR, $event->exception->getMessage());

        if ($this->recordExceptions) {
            $span->recordException($event->exception);
        }

        $span->end($this->nowNanos());

        $this->store->forget($event->context->traceId);
    }

    /**
     * Create a span for a buffered tool call under the given parent context.
     */
    protected function emitToolSpan(PendingTool $tool, ContextInterface $parent): void
    {
        $span = $this->tracer
            ->spanBuilder(GenAiAttributes::OPERATION_EXECUTE_TOOL.' '.$tool->name)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setStartTimestamp($tool->startNanos)
            ->startSpan();

        $span->setAttribute(GenAiAttributes::OPERATION_NAME, GenAiAttributes::OPERATION_EXECUTE_TOOL);
        $span->setAttribute(GenAiAttributes::TOOL_NAME, $tool->name);
        $span->setAttribute(GenAiAttributes::TOOL_CALL_ID, $tool->callId);

        // OpenInference tool span.
        $span->setAttribute(OpenInferenceAttributes::SPAN_KIND, OpenInferenceAttributes::KIND_TOOL);
        $span->setAttribute(OpenInferenceAttributes::TOOL_NAME, $tool->name);
        $span->setAttribute(OpenInferenceAttributes::TOOL_CALL_ID, $tool->callId);

        if ($tool->parameters !== null) {
            $span->setAttribute(OpenInferenceAttributes::TOOL_PARAMETERS, $this->json($tool->parameters));
            $span->setAttribute(OpenInferenceAttributes::INPUT_VALUE, $this->json($tool->parameters));
            $span->setAttribute(OpenInferenceAttributes::INPUT_MIME_TYPE, OpenInferenceAttributes::MIME_JSON);
        }

        if ($tool->result !== null) {
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, is_string($tool->result) ? $tool->result : $this->json($tool->result));
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MIME_TYPE, is_string($tool->result) ? OpenInferenceAttributes::MIME_TEXT : OpenInferenceAttributes::MIME_JSON);
        }

        if ($tool->toolIndex !== null) {
            $span->setAttribute(GenAiAttributes::TOOL_INDEX, $tool->toolIndex);
        }

        $span->end($tool->endNanos);
    }

    /**
     * Emit any tools still buffered at generation end (their step never
     * completed) as children of the root, so nothing is silently dropped.
     */
    protected function flushRemainingTools(string $traceId): void
    {
        $rootContext = $this->store->context($traceId);

        if ($rootContext === null) {
            return;
        }

        foreach ($this->store->takeRemainingTools($traceId) as $tool) {
            $this->emitToolSpan($tool, $rootContext);
        }
    }

    protected function applyUsage(SpanInterface $span, ?Usage $usage): void
    {
        if (! $usage instanceof Usage) {
            return;
        }

        $span->setAttribute(GenAiAttributes::USAGE_INPUT_TOKENS, $usage->promptTokens);
        $span->setAttribute(GenAiAttributes::USAGE_OUTPUT_TOKENS, $usage->completionTokens);

        if ($usage->cost !== null) {
            $span->setAttribute(GenAiAttributes::USAGE_COST, $usage->cost);
        }
    }

    protected function applyOpenInferenceUsage(SpanInterface $span, ?Usage $usage): void
    {
        if (! $usage instanceof Usage) {
            return;
        }

        $span->setAttribute(OpenInferenceAttributes::TOKEN_COUNT_PROMPT, $usage->promptTokens);
        $span->setAttribute(OpenInferenceAttributes::TOKEN_COUNT_COMPLETION, $usage->completionTokens);
        $span->setAttribute(OpenInferenceAttributes::TOKEN_COUNT_TOTAL, $usage->promptTokens + $usage->completionTokens);
    }

    protected function applyInput(SpanInterface $span, mixed $request): void
    {
        if (! is_object($request)) {
            return;
        }

        $payload = [];
        if (method_exists($request, 'prompt')) {
            $payload['prompt'] = $request->prompt();
        }
        if (method_exists($request, 'systemPrompts')) {
            $payload['system_prompts'] = array_map($this->arrayValue(...), $request->systemPrompts());
        }
        if (method_exists($request, 'messages')) {
            $payload['messages'] = array_map($this->arrayValue(...), $request->messages());
        }
        if (method_exists($request, 'inputs')) {
            $payload['inputs'] = $request->inputs();
        }

        if ($payload === [] && $request instanceof Arrayable) {
            $payload = $request->toArray();
        }

        if ($payload !== []) {
            $span->setAttribute(OpenInferenceAttributes::INPUT_VALUE, $this->json($payload));
            $span->setAttribute(OpenInferenceAttributes::INPUT_MIME_TYPE, OpenInferenceAttributes::MIME_JSON);
        }
    }

    protected function applyOutput(SpanInterface $span, mixed $response): void
    {
        if (! is_object($response)) {
            return;
        }

        if (property_exists($response, 'structured') && is_array($response->structured)) {
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, $this->json($response->structured));
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MIME_TYPE, OpenInferenceAttributes::MIME_JSON);

            return;
        }

        if (property_exists($response, 'text') && is_string($response->text)) {
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, $response->text);
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MIME_TYPE, OpenInferenceAttributes::MIME_TEXT);

            return;
        }

        if (property_exists($response, 'embeddings') && is_array($response->embeddings)) {
            $first = $response->embeddings[0] ?? null;
            $dimensions = is_object($first) && property_exists($first, 'embedding') && is_array($first->embedding) ? count($first->embedding) : null;
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, $this->json(['count' => count($response->embeddings), 'dimensions' => $dimensions]));
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MIME_TYPE, OpenInferenceAttributes::MIME_JSON);

            return;
        }

        if (property_exists($response, 'images') && is_array($response->images)) {
            $images = array_map(function (mixed $image): array {
                $data = $image instanceof Arrayable ? $image->toArray() : [];
                $url = is_string($data['url'] ?? null) ? $this->safeUrl($data['url']) : null;

                return array_filter([
                    'url' => $url,
                    'mime_type' => $data['mime_type'] ?? null,
                    'revised_prompt' => $data['revised_prompt'] ?? null,
                ], fn (mixed $value): bool => $value !== null);
            }, $response->images);
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, $this->json($images));
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MIME_TYPE, OpenInferenceAttributes::MIME_JSON);
        }
    }

    protected function applyStepContent(SpanInterface $span, mixed $step): void
    {
        if (! $step instanceof Arrayable) {
            return;
        }

        $data = $step->toArray();
        $messages = [...($data['system_prompts'] ?? []), ...($data['messages'] ?? [])];

        foreach ($messages as $index => $message) {
            if (! is_array($message)) {
                continue;
            }
            $span->setAttribute(OpenInferenceAttributes::INPUT_MESSAGES.'.'.$index.'.message.role', (string) ($message['type'] ?? 'user'));
            $content = $message['content'] ?? $message;
            $span->setAttribute(OpenInferenceAttributes::INPUT_MESSAGES.'.'.$index.'.message.content', is_string($content) ? $content : $this->json($content));
        }

        if (array_key_exists('text', $data)) {
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, (string) $data['text']);
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MIME_TYPE, OpenInferenceAttributes::MIME_TEXT);
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MESSAGES.'.0.message.role', 'assistant');
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MESSAGES.'.0.message.content', (string) $data['text']);
        }
    }

    /** @return array<string, mixed>|string|int|float|bool|null */
    protected function arrayValue(mixed $value): array|string|int|float|bool|null
    {
        if ($value instanceof Arrayable) {
            return $value->toArray();
        }

        if (is_array($value) || is_scalar($value) || $value === null) {
            return $value;
        }

        return ['type' => get_debug_type($value)];
    }

    protected function safeUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme']).'://'.$parts['host'].$port.($parts['path'] ?? '');
    }

    protected function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function applyFinishReason(SpanInterface $span, ?FinishReason $finishReason): void
    {
        if (! $finishReason instanceof FinishReason) {
            return;
        }

        $span->setAttribute(GenAiAttributes::RESPONSE_FINISH_REASONS, [$finishReason->name]);
    }

    /**
     * OpenInference span kind for the root generation span: embeddings map to an
     * EMBEDDING span; text/structured/image generations are a CHAIN that
     * contains the per-step LLM spans and any tool spans.
     */
    protected function rootSpanKind(TelemetryOperation $operation): string
    {
        return $operation === TelemetryOperation::Embeddings
            ? OpenInferenceAttributes::KIND_EMBEDDING
            : OpenInferenceAttributes::KIND_CHAIN;
    }

    protected function operationName(TelemetryOperation $operation): string
    {
        return match ($operation) {
            TelemetryOperation::Embeddings => 'embeddings',
            TelemetryOperation::Image => 'image_generation',
            default => 'chat',
        };
    }

    /**
     * Current epoch time in nanoseconds (microsecond resolution), computed
     * without the float precision loss of casting microtime(true) * 1e9.
     */
    protected function nowNanos(): int
    {
        $now = microtime(true);
        $seconds = (int) $now;

        return $seconds * 1_000_000_000 + (int) round(($now - $seconds) * 1_000_000_000);
    }
}
