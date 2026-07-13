<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ContextInterface;

/**
 * Holds the open root span for each in-flight generation, keyed by trace id.
 *
 * Prism's telemetry events carry a stable trace id and explicit step/tool
 * ordinals, so children are parented deterministically off the stored root
 * context rather than ambient OpenTelemetry scope. `boundaryNanos` tracks the
 * end of the last step so the next step span can start where the previous one
 * finished, yielding contiguous step spans.
 */
class SpanStore
{
    /** @var array<string, array{span: SpanInterface, context: ContextInterface, boundaryNanos: int}> */
    protected array $roots = [];

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

    public function forget(string $traceId): void
    {
        unset($this->roots[$traceId]);
    }
}
