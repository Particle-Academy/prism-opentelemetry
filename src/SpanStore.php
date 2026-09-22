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

    /**
     * The tools a generation advertised, held until the span is ending.
     *
     * NOT written when they arrive, which is the whole reason this exists. The
     * OpenTelemetry SDK caps a span at 128 attributes by default and drops the
     * excess SILENTLY, and a tool list is two attributes per tool ungated and
     * four under capture. Written at start, a step offering 31 tools filled the
     * span before anything about the OUTCOME of the call — usage, finish
     * reason, input, output — had been set, and a consumer saw roots carrying a
     * model and a tool list and nothing else, with no error anywhere.
     *
     * Held here and written LAST, the same truncation costs the END OF THE TOOL
     * LIST instead of the result of the call. Both are lossy at the limit; only
     * one of them is legible.
     *
     * @var array<string, array<non-empty-string, scalar>>
     */
    protected array $advertisedTools = [];

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

    /**
     * @param  array<non-empty-string, scalar>  $tools
     */
    public function holdAdvertisedTools(string $traceId, array $tools): void
    {
        $this->advertisedTools[$traceId] = $tools;
    }

    /**
     * @return array<non-empty-string, scalar>
     */
    public function takeAdvertisedTools(string $traceId): array
    {
        $tools = $this->advertisedTools[$traceId] ?? [];

        unset($this->advertisedTools[$traceId]);

        return $tools;
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
        unset(
            $this->roots[$traceId],
            $this->stepContexts[$traceId],
            $this->pendingTools[$traceId],
            // `takeAdvertisedTools` already clears these on the normal path.
            // Cleared here too because a generation that never completes would
            // otherwise leave its tool list behind — in a long-lived queue
            // worker that is an unbounded hold on every generation it ever saw.
            $this->advertisedTools[$traceId],
        );
    }
}
