<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * Holds the open root span for each in-flight generation, keyed by trace id.
 *
 * Prism's telemetry events carry a stable trace id and explicit step/tool
 * ordinals, so children are parented deterministically off the stored contexts
 * rather than ambient OpenTelemetry scope. `boundaryNanos` tracks the end of the
 * last step so the next step span can start where the previous one finished,
 * yielding contiguous step spans.
 *
 * Tool and step events arrive in *different orders* across Prism's paths:
 * non-streaming replays every step at the end (so tools land before their step),
 * while streaming completes a step before its tools run. To nest correctly
 * regardless, the store keeps each step span's context keyed by step index and
 * buffers any tool whose step span does not exist yet — the subscriber drains
 * the buffer the moment the matching step context appears.
 */
class SpanStore
{
    /** @var array<string, array{span: SpanInterface, context: ContextInterface, boundaryNanos: int}> */
    protected array $roots = [];

    /** @var array<string, array<int, ContextInterface>> */
    protected array $stepContexts = [];

    /** @var array<string, list<PendingTool>> */
    protected array $pendingTools = [];

    public function start(string $traceId, SpanInterface $span, ContextInterface $context, int $startNanos): void
    {
        $this->roots[$traceId] = [
            'span' => $span,
            'context' => $context,
            'boundaryNanos' => $startNanos,
        ];
    }

    public function has(string $traceId): bool
    {
        return isset($this->roots[$traceId]);
    }

    public function span(string $traceId): ?SpanInterface
    {
        return $this->roots[$traceId]['span'] ?? null;
    }

    public function context(string $traceId): ?ContextInterface
    {
        return $this->roots[$traceId]['context'] ?? null;
    }

    public function boundaryNanos(string $traceId): ?int
    {
        return $this->roots[$traceId]['boundaryNanos'] ?? null;
    }

    public function setBoundaryNanos(string $traceId, int $nanos): void
    {
        if (isset($this->roots[$traceId])) {
            $this->roots[$traceId]['boundaryNanos'] = $nanos;
        }
    }

    /**
     * Record the context of a step span so later tool spans can parent under it.
     */
    public function recordStepContext(string $traceId, int $stepIndex, ContextInterface $context): void
    {
        $this->stepContexts[$traceId][$stepIndex] = $context;
    }

    public function stepContext(string $traceId, int $stepIndex): ?ContextInterface
    {
        return $this->stepContexts[$traceId][$stepIndex] ?? null;
    }

    /**
     * Buffer a tool invocation whose owning step span does not exist yet.
     */
    public function bufferTool(string $traceId, PendingTool $tool): void
    {
        $this->pendingTools[$traceId][] = $tool;
    }

    /**
     * Remove and return the buffered tools belonging to a given step.
     *
     * @return list<PendingTool>
     */
    public function takeToolsForStep(string $traceId, int $stepIndex): array
    {
        $claimed = [];
        $remaining = [];

        foreach ($this->pendingTools[$traceId] ?? [] as $tool) {
            if ($tool->stepIndex === $stepIndex) {
                $claimed[] = $tool;

                continue;
            }

            $remaining[] = $tool;
        }

        $this->pendingTools[$traceId] = $remaining;

        return $claimed;
    }

    /**
     * Remove and return any tools still buffered for a trace — used at
     * generation end so a tool whose step never materialised is not lost.
     *
     * @return list<PendingTool>
     */
    public function takeRemainingTools(string $traceId): array
    {
        $tools = $this->pendingTools[$traceId] ?? [];

        unset($this->pendingTools[$traceId]);

        return $tools;
    }

    public function forget(string $traceId): void
    {
        unset($this->roots[$traceId], $this->stepContexts[$traceId], $this->pendingTools[$traceId]);
    }
}
