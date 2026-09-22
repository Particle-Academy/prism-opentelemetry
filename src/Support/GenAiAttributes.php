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

    /**
     * INCLUDES CACHED TOKENS, by the convention's own wording: "This value
     * SHOULD include all types of input tokens, including cached tokens."
     *
     * Prism's `Usage` is the other way round — `promptTokens` is normalised to
     * EXCLUDE them, and the OpenAI handler subtracts `cached_tokens` from
     * `input_tokens` to make that true. So this attribute is not
     * `$usage->promptTokens`; it is that plus the two cache counts, and it was
     * wrong here on every cached request until it was.
     */
    public const USAGE_INPUT_TOKENS = 'gen_ai.usage.input_tokens';

    /**
     * Includes reasoning tokens, which Prism's `completionTokens` already does
     * — providers report `output_tokens` inclusive of thinking, and Prism does
     * not subtract it. Nothing to reconcile on this one.
     */
    public const USAGE_OUTPUT_TOKENS = 'gen_ai.usage.output_tokens';

    /**
     * The cache and reasoning breakdowns.
     *
     * Checked 2026-09-21 against the LIVE registry, which is now
     * `open-telemetry/semantic-conventions-genai` — the `gen_ai.*` attributes
     * MOVED there and every one of them reads "deprecated" in the original
     * `semantic-conventions` repo. That badge describes the move, not a
     * rename: `gen_ai.usage.input_tokens` above is still the current name.
     *
     * The move matters for one of these. The old page spells cache writes
     * `gen_ai.usage.cache_creation.input_tokens`; the live registry spells it
     * `cache_write` and carries no `cache_creation` at all. Taking the first
     * page at its word would have emitted an attribute no backend is looking
     * for — present in the span, invisible on the dashboard, and indexed under
     * a name that means nothing.
     *
     * All three are stability "Development", so they can still move. That is a
     * reason to record the date they were checked, not a reason to invent our
     * own names: a consumer aggregating across instrumentations needs the
     * spelling everyone else uses.
     */
    public const USAGE_CACHE_READ_INPUT_TOKENS = 'gen_ai.usage.cache_read.input_tokens';

    public const USAGE_CACHE_WRITE_INPUT_TOKENS = 'gen_ai.usage.cache_write.input_tokens';

    public const USAGE_REASONING_OUTPUT_TOKENS = 'gen_ai.usage.reasoning.output_tokens';

    public const TOOL_NAME = 'gen_ai.tool.name';

    public const TOOL_CALL_ID = 'gen_ai.tool.call.id';

    // Prism-specific attributes (namespaced to avoid colliding with semconv).
    public const USAGE_COST = 'gen_ai.usage.cost';

    public const STEP_INDEX = 'prism.step.index';

    public const TOOL_INDEX = 'prism.tool.index';

    public const OPERATION_EXECUTE_TOOL = 'execute_tool';

    /**
     * Provider rate limits — quota headroom, beside the latency.
     *
     * THE SEMANTIC CONVENTIONS DEFINE NOTHING FOR THIS. Checked 2026-09-05
     * against the gen_ai and http attribute registries: `gen_ai.*` has usage,
     * request and response namespaces and no quota anywhere in them, and the
     * closest thing in all of semconv is the generic, opt-in
     * `http.response.header.<key>` capture — which records a header verbatim
     * and knows nothing about which bucket it describes. `gen_ai.error.type`
     * has a `rate_limit` member, but that names a failure, not a headroom.
     *
     * So these are CUSTOM names, and they live under `prism.` — beside
     * {@see self::STEP_INDEX} — rather than inside `gen_ai.`. Squatting in a
     * standard namespace is worse than being outside it: when a real
     * `gen_ai.rate_limit.*` arrives, a backend must not find two spellings of
     * it meaning subtly different things. ({@see self::USAGE_COST} is the
     * counter-example already in this file: it claims to be namespaced away
     * from semconv while sitting directly inside `gen_ai.usage.`.)
     *
     * A rate limit is a LIST of buckets — requests, tokens, input-tokens — and
     * a span attribute is flat, so the list is flattened BY BUCKET NAME:
     *
     *     prism.rate_limit.buckets                  ["requests","tokens"]
     *     prism.rate_limit.requests.limit           1000
     *     prism.rate_limit.requests.remaining       999
     *     prism.rate_limit.requests.resets_at_unix  1788611696
     *
     * Name-keyed rather than index-keyed (`…rate_limit.0.limit`) or serialised
     * into one JSON blob, because the whole point is that a backend can FILTER
     * on it: `prism.rate_limit.tokens.remaining < 1000` is a numeric predicate
     * a dashboard can express, and it does not depend on which position the
     * provider happened to list the bucket in. A JSON blob is unfilterable, and
     * an index is a stable key for an unstable thing.
     *
     * The cost of name-keying is that the ATTRIBUTE KEY SPACE becomes
     * provider-controlled, which is a real hazard — backends index keys, and
     * unbounded keys are how an observability bill becomes an incident. Hence
     * {@see self::RATE_LIMIT_NAME_ALPHABET} and
     * {@see self::RATE_LIMIT_MAX_BUCKETS}.
     */
    public const RATE_LIMIT_PREFIX = 'prism.rate_limit.';

    public const RATE_LIMIT_BUCKETS = 'prism.rate_limit.buckets';

    public const RATE_LIMIT_FIELD_LIMIT = 'limit';

    public const RATE_LIMIT_FIELD_REMAINING = 'remaining';

    /**
     * An INTEGER Unix epoch in SECONDS, floored — never a formatted date.
     *
     * Date formatting is precisely where three languages produce three strings
     * from one instant: an ISO-8601 rendering differs on the offset spelling
     * (`+00:00` vs `Z`), on whether fractional seconds appear, and on how many
     * digits of them. None of that errors; the two services simply stop
     * matching. An integer has one spelling in all three languages.
     *
     * The `_unix` suffix is not decoration. `ProviderRateLimit::toArray()`
     * emits `resets_at` as an ISO-8601 STRING, and a reader who saw the same
     * key here would reasonably expect the same value.
     */
    public const RATE_LIMIT_FIELD_RESETS_AT = 'resets_at_unix';

    /**
     * The only characters a bucket name may contain, spelled out.
     *
     * Not a regex, not `ctype_*`, not the language's own `strtolower` — an
     * explicit codepoint set, spelled identically in PHP, TypeScript and
     * Python. This ecosystem has been bitten by the alternative: a single
     * trailing space defeated a tool-name reservation in all three languages at
     * once, and closing it with each language's own `trim()` would have shut
     * the ASCII hole and opened three new Unicode ones.
     *
     * A bucket whose name contains anything else is DROPPED, not repaired.
     * Repairing means normalising, and normalising means two distinct names can
     * collapse onto one key — so a bucket called `tokens\u{200B}` could
     * overwrite the real `tokens`. Dropping cannot collide with anything.
     *
     * Every accepted character is one byte, so the length limit below measures
     * the same thing whether it is counted in bytes (PHP), UTF-16 code units
     * (JavaScript) or codepoints (Python). That is why the alphabet is checked
     * FIRST and the length second.
     */
    public const RATE_LIMIT_NAME_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789-_';

    public const RATE_LIMIT_MAX_NAME_LENGTH = 64;

    /**
     * At most this many buckets reach a span, in the order the provider gave.
     *
     * The alphabet gate bounds what a key may LOOK like; it does not bound how
     * many there are. A provider (or anything sitting between us and one) that
     * returned ten thousand well-formed bucket names would otherwise put ten
     * thousand distinct attribute keys on every span.
     */
    public const RATE_LIMIT_MAX_BUCKETS = 16;
}
