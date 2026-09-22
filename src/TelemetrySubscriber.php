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
use Prism\OpenTelemetry\Support\MediaContent;
use Prism\OpenTelemetry\Support\OpenInferenceAttributes;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Events\Telemetry\StepCompleted;
use Prism\Prism\Events\Telemetry\ToolInvoked;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\AdvertisedTool;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\Usage;
use Throwable;

/**
 * Builds GenAI-convention OpenTelemetry spans from Prism's telemetry events.
 *
 * One root span per generation; child spans per step and per tool call, parented
 * deterministically off the stored root context via the trace id (never ambient
 * scope, which does not survive Prism's recursive tool loop).
 */
class TelemetrySubscriber
{
    /**
     * At most this many tools reach a span.
     *
     * The attribute KEY carries an index, so an unbounded tool list is an
     * unbounded key space — the hazard the rate-limit bucket cap exists for,
     * arriving by a different route. A model given more than 64 tools has a
     * bigger problem than its telemetry.
     */
    protected const MAX_TOOLS = 64;

    /**
     * And at most this many bytes of a tool's name.
     *
     * `prism-mcp` builds tools from a REMOTE server's advertised definitions,
     * so a name is not always ours to trust, and since the ungated half runs on
     * every generation a long one would ride every span in the system.
     */
    protected const MAX_TOOL_NAME_BYTES = 512;

    public function __construct(
        protected TracerInterface $tracer,
        protected SpanStore $store,
        protected bool $recordExceptions = true,
        protected int $maxContentLength = 65_536,
        protected bool $captureMedia = false,
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

        $this->applyTools($span, $event->tools, $event->request);
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
        // NOT read off `$event->response`. Core nulls the response when
        // `prism.telemetry.capture_content` is off -- the default -- so quota
        // headroom used to ride on a privacy switch it has nothing to do with,
        // and vanished from every successful generation (G-45). The event now
        // carries the buckets as their own field, unconditionally, exactly as
        // it carries usage.
        $this->applyRateLimits($span, $event->rateLimits);

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

        // The 429 is the moment an operator most wants the quota numbers, and
        // it is the one moment they are guaranteed to be reachable: a rate
        // limited generation has no response for them to travel on, but
        // PrismRateLimitedException carries them itself.
        $this->applyRateLimits($span, $this->rateLimitsOfException($event->exception));

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
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, is_string($tool->result) ? $this->bounded($tool->result) : $this->json($tool->result));
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

        $span->setAttribute(GenAiAttributes::USAGE_INPUT_TOKENS, $this->inputTokens($usage));
        $span->setAttribute(GenAiAttributes::USAGE_OUTPUT_TOKENS, $usage->completionTokens);

        // Only when the provider reported them. A null is "this provider does
        // not tell us", and publishing it as 0 would make a model with no
        // prompt caching indistinguishable from one whose cache never hit —
        // which is a question somebody reads these attributes to answer.
        if ($usage->cacheReadInputTokens !== null) {
            $span->setAttribute(GenAiAttributes::USAGE_CACHE_READ_INPUT_TOKENS, $usage->cacheReadInputTokens);
        }

        if ($usage->cacheWriteInputTokens !== null) {
            $span->setAttribute(GenAiAttributes::USAGE_CACHE_WRITE_INPUT_TOKENS, $usage->cacheWriteInputTokens);
        }

        if ($usage->thoughtTokens !== null) {
            $span->setAttribute(GenAiAttributes::USAGE_REASONING_OUTPUT_TOKENS, $usage->thoughtTokens);
        }

        if ($usage->cost !== null) {
            $span->setAttribute(GenAiAttributes::USAGE_COST, $usage->cost);
        }
    }

    /**
     * Every token that went IN, which is not what Prism's `promptTokens` is.
     *
     * Prism normalises the field to exclude cache traffic — the OpenAI handler
     * subtracts `input_tokens_details.cached_tokens` outright, and Anthropic
     * reports it separately to begin with. Both conventions this package emits
     * define their input count the other way, as the whole prompt side with the
     * cache counts already inside it.
     *
     * So the reconciliation happens here, once, and the two callers agree by
     * construction. Getting it wrong is not a rounding error: a cached
     * Anthropic turn reports 922 input tokens where 35,600 went in, and a cost
     * view built on that under-reports by about 97% on exactly the workload
     * caching exists for.
     */
    protected function inputTokens(Usage $usage): int
    {
        return $usage->promptTokens
            + ($usage->cacheReadInputTokens ?? 0)
            + ($usage->cacheWriteInputTokens ?? 0);
    }

    /**
     * The rate limits an exception carries, or none.
     *
     * @return array<int, mixed>
     */
    protected function rateLimitsOfException(Throwable $exception): array
    {
        if (! $exception instanceof PrismRateLimitedException) {
            return [];
        }

        return array_values($exception->rateLimits);
    }

    /**
     * Flatten the provider's rate-limit buckets onto the span.
     *
     * Present-and-empty and absent are different values to a backend, so a
     * provider that reported no rate limits writes NOTHING here. That is the
     * ORDINARY case rather than an edge one: Azure, OpenRouter, Requesty,
     * Perplexity and XAI all pass `rateLimits: []`, and so does every span a
     * stream produces. An empty `prism.rate_limit.buckets` would claim we asked
     * and were told nothing, which is not the same as never having been told.
     *
     * The same rule one level down: a bucket contributes a key only for the
     * fields the provider actually sent, and a bucket that sent no field at all
     * does not appear in `buckets` either. See {@see GenAiAttributes} for why
     * the flattening is by name, and what bounds the key space.
     *
     * @param  array<int, mixed>  $rateLimits
     */
    protected function applyRateLimits(SpanInterface $span, array $rateLimits): void
    {
        /** @var array<int, string> $exported */
        $exported = [];

        foreach ($rateLimits as $rateLimit) {
            if (count($exported) >= GenAiAttributes::RATE_LIMIT_MAX_BUCKETS) {
                break;
            }

            // The arrays above are typed only by PHPDoc, and a docblock is not
            // a check: a hand-built Meta or exception can carry anything.
            if (! $rateLimit instanceof ProviderRateLimit) {
                continue;
            }

            $name = $this->rateLimitBucketName($rateLimit->name);

            // FIRST bucket of a name wins. A later duplicate — which only a
            // hand-built list or a hostile provider produces — must not be able
            // to overwrite the numbers already on the span.
            if ($name === null || in_array($name, $exported, true)) {
                continue;
            }

            $fields = [];

            if ($rateLimit->limit !== null) {
                $fields[GenAiAttributes::RATE_LIMIT_FIELD_LIMIT] = $rateLimit->limit;
            }

            if ($rateLimit->remaining !== null) {
                $fields[GenAiAttributes::RATE_LIMIT_FIELD_REMAINING] = $rateLimit->remaining;
            }

            if ($rateLimit->resetsAt !== null) {
                // Seconds, floored. DateTimeInterface::getTimestamp() discards
                // microseconds rather than rounding, which is the same
                // direction as Math.floor and math.floor in the ports.
                $fields[GenAiAttributes::RATE_LIMIT_FIELD_RESETS_AT] = $rateLimit->resetsAt->getTimestamp();
            }

            if ($fields === []) {
                continue;
            }

            foreach ($fields as $field => $value) {
                $span->setAttribute(GenAiAttributes::RATE_LIMIT_PREFIX.$name.'.'.$field, $value);
            }

            $exported[] = $name;
        }

        if ($exported !== []) {
            $span->setAttribute(GenAiAttributes::RATE_LIMIT_BUCKETS, $exported);
        }
    }

    /**
     * A bucket name that is safe to make part of an attribute KEY, or null.
     *
     * Alphabet first, length second — see {@see GenAiAttributes::RATE_LIMIT_NAME_ALPHABET}.
     */
    protected function rateLimitBucketName(string $name): ?string
    {
        if ($name === '') {
            return null;
        }

        $length = strlen($name);

        for ($i = 0; $i < $length; $i++) {
            if (! str_contains(GenAiAttributes::RATE_LIMIT_NAME_ALPHABET, $name[$i])) {
                return null;
            }
        }

        return $length > GenAiAttributes::RATE_LIMIT_MAX_NAME_LENGTH ? null : $name;
    }

    protected function applyOpenInferenceUsage(SpanInterface $span, ?Usage $usage): void
    {
        if (! $usage instanceof Usage) {
            return;
        }

        $prompt = $this->inputTokens($usage);

        $span->setAttribute(OpenInferenceAttributes::TOKEN_COUNT_PROMPT, $prompt);
        $span->setAttribute(OpenInferenceAttributes::TOKEN_COUNT_COMPLETION, $usage->completionTokens);

        // Derived from the RECONCILED prompt, not from Prism's raw field. The
        // total was previously the sum of the two mapped counts, so it inherited
        // the cache gap and compounded it — the one attribute a cost view is
        // most likely to read, and the one furthest from the truth.
        $span->setAttribute(OpenInferenceAttributes::TOKEN_COUNT_TOTAL, $prompt + $usage->completionTokens);

        // Sub-counts of the prompt, by the spec's own wording. Absent when the
        // provider did not report them, for the same reason as the gen_ai side.
        if ($usage->cacheReadInputTokens !== null) {
            $span->setAttribute(
                OpenInferenceAttributes::TOKEN_COUNT_PROMPT_DETAILS_CACHE_READ,
                $usage->cacheReadInputTokens,
            );
        }

        if ($usage->cacheWriteInputTokens !== null) {
            $span->setAttribute(
                OpenInferenceAttributes::TOKEN_COUNT_PROMPT_DETAILS_CACHE_WRITE,
                $usage->cacheWriteInputTokens,
            );
        }

        if ($usage->thoughtTokens !== null) {
            $span->setAttribute(
                OpenInferenceAttributes::TOKEN_COUNT_COMPLETION_DETAILS_REASONING,
                $usage->thoughtTokens,
            );
        }
    }

    /**
     * The tools the model was offered, in two halves that answer two questions.
     *
     * THE UNGATED HALF — name and digest — comes from the EVENT, which carries
     * it whatever `prism.telemetry.capture_content` says. It answers "did the
     * tool set change between these two turns", which is what somebody asks
     * when a provider's prompt cache missed and the bill went up. That question
     * is asked in production, and production is exactly where the content gate
     * is off, so a gated answer would be absent whenever it was wanted. Same
     * reasoning that put rate limits outside the gate in G-45.
     *
     * THE GATED HALF — description and JSON schema — comes from the REQUEST,
     * which core replaces with null unless capture is on. It answers "WHAT
     * changed", and a tool description is instructions to a model, so it
     * belongs behind the gate. A reader who has the first half and wants the
     * second can turn capture on, or read the definition in their own repo.
     *
     * The index ties them together: `llm.tools.0.tool.name` and
     * `prism.tools.0.digest` are the same tool, and `llm.tools.0.tool.description`
     * joins them when capture is on.
     *
     * @param  array<int, mixed>  $tools
     */
    protected function applyTools(SpanInterface $span, array $tools, mixed $request): void
    {
        // Bounded, because a tool name is not necessarily ours. `prism-mcp`
        // builds tools from a REMOTE server's advertised definitions, so a
        // hostile or careless one can supply a very long name, and this now
        // runs on every generation rather than only under capture. The cap is
        // on the VALUE, not a key, so there is no key-space hazard of the kind
        // the rate-limit alphabet exists for -- but an unbounded string on
        // every span is still somebody's observability bill.
        $definitions = $this->toolDefinitions($request);

        foreach (array_slice(array_values($tools), 0, self::MAX_TOOLS) as $index => $tool) {
            if (! $tool instanceof AdvertisedTool) {
                continue;
            }

            $span->setAttribute(
                OpenInferenceAttributes::TOOLS_PREFIX.$index.'.'.OpenInferenceAttributes::TOOL_FIELD_NAME,
                $this->boundedName($tool->name),
            );
            $span->setAttribute(
                GenAiAttributes::TOOLS_PREFIX.$index.'.'.GenAiAttributes::TOOL_FIELD_DIGEST,
                $tool->digest,
            );

            $definition = $definitions[$tool->name] ?? null;

            if ($definition === null) {
                continue;
            }

            $span->setAttribute(
                OpenInferenceAttributes::TOOLS_PREFIX.$index.'.'.OpenInferenceAttributes::TOOL_FIELD_DESCRIPTION,
                $this->bounded($definition['description']),
            );
            $span->setAttribute(
                OpenInferenceAttributes::TOOLS_PREFIX.$index.'.'.OpenInferenceAttributes::TOOL_FIELD_JSON_SCHEMA,
                $this->json($definition['parameters']),
            );
        }
    }

    /**
     * Descriptions and schemas, keyed by tool name, or none.
     *
     * Reached only through the request, so this is empty exactly when capture
     * is off — the gate is core's and is not re-implemented here.
     *
     * @return array<string, array{description: string, parameters: array<string, mixed>}>
     */
    protected function toolDefinitions(mixed $request): array
    {
        if (! is_object($request) || ! method_exists($request, 'tools')) {
            return [];
        }

        $tools = $request->tools();

        if (! is_array($tools)) {
            return [];
        }

        $definitions = [];

        foreach ($tools as $tool) {
            if (! $tool instanceof Tool) {
                continue;
            }

            $definitions[$tool->name()] = [
                'description' => $tool->description(),
                'parameters' => $tool->parametersAsArray(),
            ];
        }

        return $definitions;
    }

    /**
     * A tool name, capped hard and separately from captured content.
     *
     * Not `bounded()`: that ruler is `prism.telemetry.content_max_length`, which
     * an operator raises to see more of a prompt. A name is not content and has
     * no reason to follow it — 512 bytes is longer than any real tool name and
     * short enough that a hostile one cannot dominate a span.
     */
    protected function boundedName(string $name): string
    {
        return mb_strcut($name, 0, self::MAX_TOOL_NAME_BYTES);
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
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, $this->bounded($response->text));
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
            $span->setAttribute(OpenInferenceAttributes::INPUT_MESSAGES.'.'.$index.'.message.content', is_string($content) ? $this->bounded($content) : $this->json($content));
        }

        if (array_key_exists('text', $data)) {
            $text = $this->bounded((string) $data['text']);
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_VALUE, $text);
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MIME_TYPE, OpenInferenceAttributes::MIME_TEXT);
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MESSAGES.'.0.message.role', 'assistant');
            $span->setAttribute(OpenInferenceAttributes::OUTPUT_MESSAGES.'.0.message.content', $text);
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
        // Every structured content attribute passes through here, so this is
        // the one place media bytes are withheld. See MediaContent.
        if (! $this->captureMedia) {
            $value = MediaContent::withoutBytes($value);
        }

        // Fail-safe: telemetry must never throw into the app. Substitute bad
        // UTF-8 and emit partial output instead of aborting the generation.
        $encoded = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->bounded(is_string($encoded) ? $encoded : '');
    }

    /**
     * Cap a captured-content attribute so a hostile or high-volume payload
     * cannot bloat a span (and the OTLP export). Mirrors prism's telemetry
     * `content_max_length`; a non-positive limit disables the cap.
     */
    protected function bounded(string $value): string
    {
        if ($this->maxContentLength <= 0 || strlen($value) <= $this->maxContentLength) {
            return $value;
        }

        return mb_strcut($value, 0, $this->maxContentLength, 'UTF-8').'…[truncated]';
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
