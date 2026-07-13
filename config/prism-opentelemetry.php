<?php

declare(strict_types=1);

return [
    // Subscribe the span-building listener to Prism's telemetry events. When
    // false, this package does nothing even if installed.
    'enabled' => env('PRISM_OTEL_ENABLED', true),

    // Instrumentation scope name used when obtaining the tracer.
    'tracer_name' => env('PRISM_OTEL_TRACER_NAME', 'prism'),

    // Record the thrown exception on the span when a generation fails.
    'record_exceptions' => env('PRISM_OTEL_RECORD_EXCEPTIONS', true),
];
