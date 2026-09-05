<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\Support\GenAiAttributes;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationFailed;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Telemetry\TelemetryContext;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\Usage;

/**
 * The cross-language span-attribute corpus from `prism-parity`.
 *
 * This package is the REFERENCE, so this file proves the corpus has not drifted
 * from the code it was generated against — which is what makes the ports'
 * "I differ from the reference HERE and nowhere else" assertions mean anything.
 * Without it they would be pinned to a snapshot of PHP that PHP had moved on
 * from, and every one of them would stay green while the claim quietly stopped
 * being true.
 *
 * A span leaves the application and is read by a backend that does not know
 * which language produced it. That is why this is compared across languages at
 * all: nothing here errors when it drifts, the two services simply stop
 * matching on a dashboard's search.
 */

/**
 * The request/response object the bridge is handed.
 *
 * The bridge probes for `prompt()`, `systemPrompts()`, `messages()` and
 * `inputs()` before falling back to `toArray()`. Carrying the corpus payload
 * through that fallback is what lets all three languages be handed the SAME
 * structure — reproducing Prism's request objects in TypeScript and Python
 * would pin the reproduction rather than the bridge.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class CorpusPayload implements Arrayable
{
    /** @param array<string, mixed> $payload */
    public function __construct(private array $payload) {}

    /** @return array<string, mixed> */
    #[\Override]
    public function toArray(): array
    {
        return $this->payload;
    }
}

/**
 * The response object this bridge is handed for one case.
 *
 * Only the OUTPUT travels here. Rate limits used to have to as well -- the
 * reference's only channel for them was the response's Meta, so a case
 * declaring quota needed a response object even with capture off, which is a
 * state core never actually produces. That was G-45, and closing it made all
 * three languages take the buckets as the completion event's own argument.
 *
 * @param  array<string, mixed>  $generation
 */
function corpusResponse(array $generation): ?CorpusPayload
{
    return $generation['output'] === null ? null : new CorpusPayload($generation['output']);
}

/**
 * The quota buckets one case declares, as the value objects the event carries.
 *
 * `resets_at` is parsed HERE and not in the bridge: the bridge is handed an
 * instant, so nothing in this comparison depends on three languages agreeing
 * about how to render or re-render a date.
 *
 * @param  array<string, mixed>  $generation
 * @return array<int, ProviderRateLimit>
 */
function corpusRateLimits(array $generation): array
{
    return array_map(static fn (array $rateLimit): ProviderRateLimit => new ProviderRateLimit(
        name: $rateLimit['name'],
        limit: $rateLimit['limit'],
        remaining: $rateLimit['remaining'],
        resetsAt: $rateLimit['resets_at'] === null ? null : new Carbon($rateLimit['resets_at']),
    ), $generation['rate_limits'] ?? []);
}

/**
 * Just the rate-limit attributes of one language's recorded span.
 *
 * @return array<string, mixed>
 */
function corpusRateLimitAttributes(string $caseId, string $language): array
{
    $case = collect(spanAttributeCorpus())->firstWhere('id', $caseId);

    return array_filter(
        $case['spans'][$language]['attributes'],
        fn (string $key): bool => str_starts_with($key, GenAiAttributes::RATE_LIMIT_PREFIX),
        ARRAY_FILTER_USE_KEY,
    );
}

/** @return array<int, array<string, mixed>> */
function spanAttributeCorpus(): array
{
    /** @var array{cases: array<int, array<string, mixed>>} $document */
    $document = json_decode(
        (string) file_get_contents(__DIR__.'/../fixtures/opentelemetry-span-attributes.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $document['cases'];
}

/**
 * Drive the real subscriber for one case and read the root span back.
 *
 * @param  array<string, mixed>  $case
 * @return array<string, mixed>
 */
function rootSpanFor(array $case): array
{
    $generation = $case['generation'];

    $exporter = new InMemoryExporter;
    $provider = new TracerProvider(new SimpleSpanProcessor($exporter));

    $subscriber = new TelemetrySubscriber(
        $provider->getTracer('prism-parity'),
        new SpanStore,
        recordExceptions: true,
        maxContentLength: $case['max_content_length'],
    );

    $context = new TelemetryContext(
        traceId: $case['id'],
        operation: TelemetryOperation::from($generation['operation']),
        provider: $generation['provider'],
        model: $generation['model'],
        startedAt: 0.0,
        userId: $generation['user_id'],
        sessionId: $generation['session_id'],
    );

    $usage = $generation['usage'] === null ? null : new Usage(
        promptTokens: $generation['usage']['prompt_tokens'],
        completionTokens: $generation['usage']['completion_tokens'],
        cost: $generation['usage']['cost'],
    );

    $subscriber->onGenerationStarted(new GenerationStarted(
        $context,
        $generation['input'] === null ? null : new CorpusPayload($generation['input']),
    ));

    $subscriber->onGenerationCompleted(new GenerationCompleted(
        $context,
        0.0,
        $generation['finish_reason'] === null ? null : FinishReason::from($generation['finish_reason']),
        $usage,
        corpusResponse($generation),
        corpusRateLimits($generation),
    ));

    $spans = $exporter->getSpans();
    $attributes = $spans[0]->getAttributes()->toArray();
    ksort($attributes);

    return [
        'name' => $spans[0]->getName(),
        'status' => strtolower($spans[0]->getStatus()->getCode()),
        'attributes' => $attributes,
    ];
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(spanAttributeCorpus())->toHaveCount(18);
});

it('still emits the recorded reference span', function (array $case): void {
    expect(rootSpanFor($case))->toBe($case['spans']['php']);
})->with(fn (): array => collect(spanAttributeCorpus())
    ->mapWithKeys(fn (array $case): array => [$case['id'].' — '.$case['title'] => [$case]])
    ->all());

it('leaves a successful span UNSET rather than marking it ok', function (): void {
    // OpenTelemetry reserves `Ok` for a status a developer set deliberately;
    // instrumentation is meant to leave it Unset. Both ports set `ok`, which
    // is G-25 — pinned here from the reference side so the pair moves together.
    $case = collect(spanAttributeCorpus())->firstWhere('id', 'otel-0001');

    expect(rootSpanFor($case)['status'])->toBe('unset');
});

it('EXPORTS content that reached it with capture off — the gap, not the guarantee', function (): void {
    // G-28, and the reason this row exists. The reference gates content in
    // CORE: `prism.telemetry.capture_content` decides whether the event carries
    // a request at all, and this bridge simply maps whatever it is given. So a
    // replayed event, a hand-built one, or a second emitter puts user content on
    // an exported span with the application's capture switch off.
    //
    // Both ports refuse the same input, because their gate is in the bridge.
    // Asserted in the POSITIVE here — this is the reference's actual behaviour,
    // and a test claiming otherwise would be the more dangerous kind of green.
    $case = collect(spanAttributeCorpus())->firstWhere('id', 'otel-0013');

    expect($case['capture_content'])->toBeFalse();
    expect(rootSpanFor($case)['attributes'])
        ->toHaveKey('input.value')
        ->and($case['spans']['ts']['attributes'])->not->toHaveKey('input.value')
        ->and($case['spans']['py']['attributes'])->not->toHaveKey('input.value');
});

it('cuts captured content by BYTES, which is one of three rulers in use', function (): void {
    // G-27. mb_strcut measures bytes; TypeScript slices UTF-16 code units and
    // Python characters of the already-escaped string. One input, three
    // different strings, and not one of them raises anything.
    $case = collect(spanAttributeCorpus())->firstWhere('id', 'otel-0012');
    $value = rootSpanFor($case)['attributes']['input.value'];

    expect($value)->toBe('{"prompt":"日…[truncated]')
        ->and($value)->not->toBe($case['spans']['ts']['attributes']['input.value'])
        ->and($value)->not->toBe($case['spans']['py']['attributes']['input.value']);
});

it('exports the provider rate limits, which no semantic convention names', function (): void {
    // The OpenTelemetry GenAI conventions define NOTHING for rate limits or
    // quota -- checked 2026-09-05 against the gen_ai and http attribute
    // registries. `gen_ai.error.type` has a `rate_limit` member, but that names
    // a failure rather than a headroom, and the nearest mechanism in all of
    // semconv is the generic opt-in `http.response.header.<key>` capture, which
    // records a header verbatim and knows nothing about the bucket it belongs
    // to. So these keys are OURS, and they live under `prism.` rather than
    // inside `gen_ai.` so a real convention can arrive later without two
    // spellings meaning subtly different things.
    $case = collect(spanAttributeCorpus())->firstWhere('id', 'otel-0014');

    expect(rootSpanFor($case)['attributes'])->toMatchArray([
        'prism.rate_limit.buckets' => ['requests'],
        'prism.rate_limit.requests.limit' => 1000,
        'prism.rate_limit.requests.remaining' => 999,
        'prism.rate_limit.requests.resets_at_unix' => 1788611696,
    ]);
});

it('writes a key only for the fields the provider actually sent', function (): void {
    // A quota of zero and a quota nobody reported are different facts, and 0
    // says the first when the truth is the second. The cost precedent, one
    // level down.
    $attributes = rootSpanFor(collect(spanAttributeCorpus())->firstWhere('id', 'otel-0015'))['attributes'];

    expect($attributes)
        ->toHaveKey('prism.rate_limit.input-tokens.limit')
        ->not->toHaveKey('prism.rate_limit.input-tokens.resets_at_unix')
        ->and($attributes)->not->toHaveKey('prism.rate_limit.output-tokens.limit');
});

it('writes NOTHING when the provider reported no rate limits at all', function (): void {
    // Present-and-empty and absent are different values to a backend, and this
    // is the COMMON case rather than an edge one: Azure, OpenRouter, Requesty,
    // Perplexity and XAI all pass `rateLimits: []`, and so does every span a
    // stream produces. An empty `buckets` array would put "we asked, there is
    // no quota" on all of them.
    $attributes = rootSpanFor(collect(spanAttributeCorpus())->firstWhere('id', 'otel-0016'))['attributes'];

    expect(array_filter($attributes, fn (string $key): bool => str_starts_with($key, GenAiAttributes::RATE_LIMIT_PREFIX), ARRAY_FILTER_USE_KEY))
        ->toBe([]);
});

it('refuses every hostile spelling of a bucket name, and keeps the real one', function (): void {
    // A bucket name is chosen by the PROVIDER and becomes part of an attribute
    // KEY -- the G-36 shape, one layer out. Seven hostile spellings of `tokens`
    // (trailing space, trailing newline, case fold, Cyrillic homoglyph, an
    // embedded dot that would forge a nested key, an empty name, and a
    // duplicate appended after the real bucket) and one real one.
    //
    // Dropped rather than normalised: normalising means two distinct names can
    // collapse onto one key, at which point the hostile bucket overwrites the
    // real bucket's numbers instead of being ignored.
    $attributes = rootSpanFor(collect(spanAttributeCorpus())->firstWhere('id', 'otel-0017'))['attributes'];

    expect($attributes)->toMatchArray([
        'prism.rate_limit.buckets' => ['tokens'],
        'prism.rate_limit.tokens.limit' => 7,
        'prism.rate_limit.tokens.remaining' => 7,
    ]);

    // The duplicate carried 8. FIRST wins, so it did not overwrite anything.
    expect(count(array_filter($attributes, fn (string $key): bool => str_starts_with($key, GenAiAttributes::RATE_LIMIT_PREFIX), ARRAY_FILTER_USE_KEY)))->toBe(3);
});

it('caps how many buckets a span can carry, however well-formed they are', function (): void {
    // The alphabet gate bounds what a key may LOOK like and not how many there
    // are, and backends index keys.
    $attributes = rootSpanFor(collect(spanAttributeCorpus())->firstWhere('id', 'otel-0018'))['attributes'];

    expect($attributes['prism.rate_limit.buckets'])
        ->toHaveCount(GenAiAttributes::RATE_LIMIT_MAX_BUCKETS)
        ->and($attributes['prism.rate_limit.buckets'][0])->toBe('b00')
        ->and($attributes)->not->toHaveKey('prism.rate_limit.b16.limit');
});

it('exports the SAME rate-limit attributes as both ports, byte for byte', function (): void {
    // The one thing in this suite that AGREES. Every other row is pinned
    // against its own language's recorded span, which is exactly the assertion
    // that cannot see a cross-language divergence -- so the rate-limit keys are
    // compared here across the three recorded maps directly.
    $compared = 0;

    foreach (spanAttributeCorpus() as $case) {
        $php = corpusRateLimitAttributes($case['id'], 'php');

        expect($php)->toBe(corpusRateLimitAttributes($case['id'], 'ts'))
            ->and($php)->toBe(corpusRateLimitAttributes($case['id'], 'py'));

        $compared += count($php);
    }

    // Vacuity guard: three empty maps agree about nothing.
    expect($compared)->toBe(48);
});

it('exports the rate limits a rate-limited generation FAILED with', function (): void {
    // The 429 is the moment an operator most wants these numbers, and the one
    // moment they cannot arrive on a response -- there is no response, so they
    // travel on the exception instead. This USED to be the only path where they
    // were not gated by content capture, which was G-45: it meant the numbers
    // showed up exactly when it was too late to act on them.
    $exporter = new InMemoryExporter;
    $sub = new TelemetrySubscriber((new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('prism-parity'), new SpanStore);
    $ctx = new TelemetryContext('rate-limited', TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', 0.0);

    $sub->onGenerationStarted(new GenerationStarted($ctx));
    $sub->onGenerationFailed(new GenerationFailed($ctx, 5.0, PrismRateLimitedException::make([
        new ProviderRateLimit(name: 'requests', limit: 50, remaining: 0, resetsAt: Carbon::createFromTimestamp(1788611696)),
    ])));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)->toMatchArray([
        'prism.rate_limit.buckets' => ['requests'],
        'prism.rate_limit.requests.limit' => 50,
        'prism.rate_limit.requests.remaining' => 0,
        'prism.rate_limit.requests.resets_at_unix' => 1788611696,
    ]);
});
