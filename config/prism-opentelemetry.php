<?php

declare(strict_types=1);

return [
    // Subscribe the span-building listener to Prism's telemetry events. When
    // false, this package does nothing even if installed.
    'enabled' => env('PRISM_OTEL_ENABLED', true),

    // Instrumentation scope name used when obtaining the tracer.
    'tracer_name' => env('PRISM_OTEL_TRACER_NAME', 'prism'),

    // Add an `exception` event to the span when a generation fails. Its message
    // and stack trace are included only when `prism.telemetry.capture_content`
    // is on; otherwise the event carries the exception's class name alone.
    'record_exceptions' => env('PRISM_OTEL_RECORD_EXCEPTIONS', true),

    // Cap the byte length of any single captured-content span attribute
    // (prompts, completions, tool args/results) so a hostile or high-volume
    // opt-in capture cannot bloat a span or the OTLP export. 0 disables the cap.
    'content_max_length' => (int) env('PRISM_OTEL_CONTENT_MAX_LENGTH', 65_536),
];
