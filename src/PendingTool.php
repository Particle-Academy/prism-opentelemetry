<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry;

/**
 * A tool invocation captured from a ToolInvoked event, buffered until its owning
 * step completes so its span can be created as a child of the step span.
 *
 * Timestamps are computed when the tool event fires (end = now, start = end -
 * duration) so the span reflects when the tool actually ran, not when the
 * deferred step replay happens.
 */
readonly class PendingTool
{
    public function __construct(
        public string $name,
        public string $callId,
        public int $startNanos,
        public int $endNanos,
        public ?int $stepIndex = null,
        public ?int $toolIndex = null,
        /** @var array<string, mixed>|null */
        public ?array $parameters = null,
        public mixed $result = null,
    ) {}
}
