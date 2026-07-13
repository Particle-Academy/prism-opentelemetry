<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Support;

/**
 * OpenTelemetry GenAI semantic-convention attribute + value keys.
 *
 * Held here (rather than pulled from open-telemetry/sem-conv) so that churn in
 * the still-evolving GenAI conventions is a release of this package, not a hard
 * dependency bump. See https://opentelemetry.io/docs/specs/semconv/gen-ai/.
 */
final class GenAiAttributes
{
    public const SYSTEM = 'gen_ai.system';

    public const OPERATION_NAME = 'gen_ai.operation.name';

    public const REQUEST_MODEL = 'gen_ai.request.model';

    public const RESPONSE_FINISH_REASONS = 'gen_ai.response.finish_reasons';

    public const USAGE_INPUT_TOKENS = 'gen_ai.usage.input_tokens';

    public const USAGE_OUTPUT_TOKENS = 'gen_ai.usage.output_tokens';

    public const TOOL_NAME = 'gen_ai.tool.name';

    public const TOOL_CALL_ID = 'gen_ai.tool.call.id';

    // Prism-specific attributes (namespaced to avoid colliding with semconv).
    public const USAGE_COST = 'gen_ai.usage.cost';

    public const STEP_INDEX = 'prism.step.index';

    public const TOOL_INDEX = 'prism.tool.index';

    public const OPERATION_EXECUTE_TOOL = 'execute_tool';
}
