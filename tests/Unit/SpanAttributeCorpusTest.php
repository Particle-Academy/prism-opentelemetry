<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Unit;

use Illuminate\Contracts\Support\Arrayable;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Events\Telemetry\GenerationCompleted;
use Prism\Prism\Events\Telemetry\GenerationStarted;
use Prism\Prism\Telemetry\TelemetryContext;
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
        $generation['output'] === null ? null : new CorpusPayload($generation['output']),
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
    expect(spanAttributeCorpus())->toHaveCount(13);
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
