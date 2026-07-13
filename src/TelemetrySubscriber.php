<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry;

use Illuminate\Contracts\Events\Dispatcher;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Prism\OpenTelemetry\Support\GenAiAttributes;
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
        $parent = $this->store->context($traceId);

        if ($parent === null) {
            return;
        }

        $end = $this->nowNanos();
        $start = $this->store->boundaryNanos($traceId) ?? $end;

        $span = $this->tracer
            ->spanBuilder('step '.($event->context->stepIndex ?? 0))
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setStartTimestamp($start)
            ->startSpan();

        if ($event->context->stepIndex !== null) {
            $span->setAttribute(GenAiAttributes::STEP_INDEX, $event->context->stepIndex);
        }

        $this->applyFinishReason($span, $event->finishReason);
        $this->applyUsage($span, $event->usage);

        $span->end($end);

        $this->store->setBoundaryNanos($traceId, $end);
    }

    public function onToolInvoked(ToolInvoked $event): void
    {
        $traceId = $event->context->traceId;
        $parent = $this->store->context($traceId);

        if ($parent === null) {
            return;
        }

        $end = $this->nowNanos();
        $start = $end - (int) round($event->durationMs * 1_000_000);

        $span = $this->tracer
            ->spanBuilder(GenAiAttributes::OPERATION_EXECUTE_TOOL.' '.$event->toolCall->name)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setStartTimestamp($start)
            ->startSpan();

        $span->setAttribute(GenAiAttributes::OPERATION_NAME, GenAiAttributes::OPERATION_EXECUTE_TOOL);
        $span->setAttribute(GenAiAttributes::TOOL_NAME, $event->toolCall->name);
        $span->setAttribute(GenAiAttributes::TOOL_CALL_ID, $event->toolCall->id);

        if ($event->context->toolIndex !== null) {
            $span->setAttribute(GenAiAttributes::TOOL_INDEX, $event->context->toolIndex);
        }

        $span->end($end);
    }

    public function onGenerationCompleted(GenerationCompleted $event): void
    {
        $span = $this->store->span($event->context->traceId);

        if (! $span instanceof SpanInterface) {
            return;
        }

        $this->applyFinishReason($span, $event->finishReason);
        $this->applyUsage($span, $event->usage);

        $span->end($this->nowNanos());

        $this->store->forget($event->context->traceId);
    }

    public function onGenerationFailed(GenerationFailed $event): void
    {
        $span = $this->store->span($event->context->traceId);

        if (! $span instanceof SpanInterface) {
            return;
        }

        $span->setStatus(StatusCode::STATUS_ERROR, $event->exception->getMessage());

        if ($this->recordExceptions) {
            $span->recordException($event->exception);
        }

        $span->end($this->nowNanos());

        $this->store->forget($event->context->traceId);
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

    protected function applyFinishReason(SpanInterface $span, ?FinishReason $finishReason): void
    {
        if (! $finishReason instanceof FinishReason) {
            return;
        }

        $span->setAttribute(GenAiAttributes::RESPONSE_FINISH_REASONS, [$finishReason->name]);
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
