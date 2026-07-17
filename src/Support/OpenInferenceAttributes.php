<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Support;

/**
 * OpenInference semantic-convention attribute + value keys.
 *
 * Arize Phoenix (and other OpenInference backends) render rich LLM/tool traces
 * from these keys rather than the OTel GenAI `gen_ai.*` keys. The bridge emits
 * both conventions so a span reads correctly in Phoenix and in any standard
 * OTLP backend. See https://github.com/Arize-ai/openinference.
 */
final class OpenInferenceAttributes
{
    public const SPAN_KIND = 'openinference.span.kind';

    public const KIND_LLM = 'LLM';

    public const KIND_CHAIN = 'CHAIN';

    public const KIND_TOOL = 'TOOL';

    public const KIND_AGENT = 'AGENT';

    public const KIND_EMBEDDING = 'EMBEDDING';

    public const LLM_MODEL_NAME = 'llm.model_name';

    public const LLM_PROVIDER = 'llm.provider';

    public const LLM_SYSTEM = 'llm.system';

    public const TOKEN_COUNT_PROMPT = 'llm.token_count.prompt';

    public const TOKEN_COUNT_COMPLETION = 'llm.token_count.completion';

    public const TOKEN_COUNT_TOTAL = 'llm.token_count.total';

    public const INPUT_VALUE = 'input.value';

    public const INPUT_MIME_TYPE = 'input.mime_type';

    public const OUTPUT_VALUE = 'output.value';

    public const OUTPUT_MIME_TYPE = 'output.mime_type';

    /** Prefix; per-message keys are `llm.input_messages.{i}.message.role|content`. */
    public const INPUT_MESSAGES = 'llm.input_messages';

    /** Prefix; per-message keys are `llm.output_messages.{i}.message.role|content`. */
    public const OUTPUT_MESSAGES = 'llm.output_messages';

    public const TOOL_NAME = 'tool.name';

    public const TOOL_CALL_ID = 'tool.id';

    public const TOOL_PARAMETERS = 'tool.parameters';

    public const SESSION_ID = 'session.id';

    public const USER_ID = 'user.id';

    public const MIME_JSON = 'application/json';

    public const MIME_TEXT = 'text/plain';
}
