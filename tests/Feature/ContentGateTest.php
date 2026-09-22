<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Contracts\Events\Dispatcher;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Prism\OpenTelemetry\SpanStore;
use Prism\OpenTelemetry\Support\GenAiAttributes;
use Prism\OpenTelemetry\Support\OpenInferenceAttributes;
use Prism\OpenTelemetry\TelemetrySubscriber;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\TelemetryOperation;
use Prism\Prism\Telemetry\Telemetry;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\Text\Request;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\Usage;

/**
 * The COMPOSED path: core's telemetry layer and this bridge, together.
 *
 * Every other test here drives the subscriber directly with a hand-built event,
 * which is the right way to pin a mapping and is exactly the wrong way to ask
 * what core actually sends. G-45 lived in that blind spot: the corpus rows for
 * rate limits were green for months while a real successful generation exported
 * none, because the generator handed the bridge a response object that core
 * would have nulled.
 *
 * So these two tests go through `Telemetry::start()`/`completed()` and the real
 * event dispatcher, with the content gate in the state it ships in.
 */
function composedHarness(): array
{
    $exporter = new InMemoryExporter;
    $subscriber = new TelemetrySubscriber(
        (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('composed'),
        new SpanStore,
    );

    app(Dispatcher::class)->subscribe($subscriber);

    return [$exporter];
}

function composedResponse(): TextResponseFake
{
    return TextResponseFake::make()
        ->withText('the completion nobody asked to export')
        ->withFinishReason(FinishReason::Stop)
        ->withUsage(new Usage(10, 5))
        ->withMeta(new Meta('resp-1', 'claude-sonnet-4-5', rateLimits: [
            new ProviderRateLimit('requests', limit: 1000, remaining: 999, resetsAt: Carbon::createFromTimestamp(1788611696)),
        ]));
}

beforeEach(function (): void {
    config()->set('prism.telemetry.enabled', true);
});

it('exports quota headroom on a SUCCESSFUL span with content capture OFF', function (): void {
    // The default configuration, and the whole of G-45. Before the fix this
    // span carried no `prism.rate_limit.*` at all: core nulled the response,
    // the response was the only channel, and the numbers turned up only once a
    // 429 had already happened -- which is the moment they stop being headroom.
    config()->set('prism.telemetry.capture_content', false);

    [$exporter] = composedHarness();

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', 'a prompt with a secret in it');
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)->toMatchArray([
        GenAiAttributes::RATE_LIMIT_BUCKETS => ['requests'],
        'prism.rate_limit.requests.limit' => 1000,
        'prism.rate_limit.requests.remaining' => 999,
        'prism.rate_limit.requests.resets_at_unix' => 1788611696,
    ]);
});

it('still withholds the content on that same span', function (): void {
    // The other half, asserted in the same shape and on the same span, because
    // "rate limits now travel unconditionally" is only safe while this stays
    // true. A future edit that widened the metadata channel into a content
    // channel would pass the test above and fail this one.
    config()->set('prism.telemetry.capture_content', false);

    [$exporter] = composedHarness();

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', 'a prompt with a secret in it');
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)
        ->not->toHaveKey(OpenInferenceAttributes::INPUT_VALUE)
        ->and($attributes)->not->toHaveKey(OpenInferenceAttributes::OUTPUT_VALUE);

    expect(json_encode($attributes))
        ->not->toContain('secret')
        ->and(json_encode($attributes))->not->toContain('the completion nobody asked to export');
});

it('exports both when the application has opted into content capture', function (): void {
    config()->set('prism.telemetry.capture_content', true);

    [$exporter] = composedHarness();

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', 'a prompt with a secret in it');
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)
        ->toHaveKey(OpenInferenceAttributes::OUTPUT_VALUE)
        ->and($attributes['prism.rate_limit.requests.remaining'])->toBe(999);
});

describe('media inside captured content', function (): void {
    // Content capture was understood to export TEXT. A message's stored form
    // carries each attachment's bytes, so without this a span carried the
    // user's uploaded file to the tracing vendor, up to the content cap.

    function capturedInput(): string
    {
        [$exporter] = composedHarness();

        $request = requestWithAnImage();

        $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', $request);
        Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

        return (string) ($exporter->getSpans()[0]->getAttributes()->toArray()[OpenInferenceAttributes::INPUT_VALUE] ?? '');
    }

    it('withholds the bytes by default, and says how big they were', function (): void {
        config()->set('prism.telemetry.capture_content', true);
        config()->set('prism.telemetry.capture_media', false);

        $input = capturedInput();

        expect($input)->toContain('What is in this?')
            ->and($input)->toContain('"mime_type":"image/png"')
            ->and($input)->toContain('"omitted_bytes":17')
            ->and($input)->not->toContain(base64_encode('SECRET-FILE-BYTES'));
    });

    it('sends the bytes when capture_media is on', function (): void {
        config()->set('prism.telemetry.capture_content', true);
        config()->set('prism.telemetry.capture_media', true);
        app()->forgetInstance(TelemetrySubscriber::class);

        $input = capturedInputWithMedia();

        expect($input)->toContain(base64_encode('SECRET-FILE-BYTES'))
            ->and($input)->not->toContain('omitted_bytes');
    });
});

function capturedInputWithMedia(): string
{
    $exporter = new InMemoryExporter;
    $subscriber = new TelemetrySubscriber(
        (new TracerProvider(new SimpleSpanProcessor($exporter)))->getTracer('composed'),
        new SpanStore,
        captureMedia: true,
    );
    app(Dispatcher::class)->subscribe($subscriber);

    $request = requestWithAnImage();

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', $request);
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    return (string) ($exporter->getSpans()[0]->getAttributes()->toArray()[OpenInferenceAttributes::INPUT_VALUE] ?? '');
}

/**
 * What the bridge reads off a text request: its messages. A stand-in rather than
 * a real Request, because this package's test app does not boot Prism's service
 * provider, and the bridge only ever calls messages() on it.
 */
function requestWithAnImage(): object
{
    return new class
    {
        /** @return list<UserMessage> */
        public function messages(): array
        {
            return [new UserMessage('What is in this?', [Image::fromBase64(base64_encode('SECRET-FILE-BYTES'), 'image/png')])];
        }
    };
}

it('counts cached prompt tokens as input, and breaks them out', function (): void {
    // prism-opentelemetry#1, reported by a consumer running Phoenix against a
    // cached Anthropic workload. `Usage` carries five token fields and only two
    // were exported, so `cacheReadInputTokens`, `cacheWriteInputTokens` and
    // `thoughtTokens` left no trace at all.
    //
    // The numbers are theirs: a turn where 35,600 tokens went in and the span
    // said 922. A cost view reading that under-reports by about 97% on exactly
    // the workload prompt caching exists for -- and it does so QUIETLY, because
    // 922 is a perfectly plausible number for a short question.
    config()->set('prism.telemetry.capture_content', false);

    [$exporter] = composedHarness();

    $usage = new Usage(
        promptTokens: 922,
        completionTokens: 210,
        cacheWriteInputTokens: 0,
        cacheReadInputTokens: 34_678,
        thoughtTokens: 64,
    );

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', 'a prompt');
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, $usage);

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)->toMatchArray([
        // BOTH conventions define their input count as the whole prompt side,
        // cache included. Prism's `promptTokens` excludes it. 922 + 34,678.
        GenAiAttributes::USAGE_INPUT_TOKENS => 35_600,
        GenAiAttributes::USAGE_OUTPUT_TOKENS => 210,
        GenAiAttributes::USAGE_CACHE_READ_INPUT_TOKENS => 34_678,
        GenAiAttributes::USAGE_CACHE_WRITE_INPUT_TOKENS => 0,
        GenAiAttributes::USAGE_REASONING_OUTPUT_TOKENS => 64,

        // The sub-counts are already inside the prompt, by OpenInference's own
        // wording, so the total is prompt + completion and nothing is counted
        // twice. It was 1,132 before -- the attribute a cost view reads first.
        OpenInferenceAttributes::TOKEN_COUNT_PROMPT => 35_600,
        OpenInferenceAttributes::TOKEN_COUNT_COMPLETION => 210,
        OpenInferenceAttributes::TOKEN_COUNT_TOTAL => 35_810,
        OpenInferenceAttributes::TOKEN_COUNT_PROMPT_DETAILS_CACHE_READ => 34_678,
        OpenInferenceAttributes::TOKEN_COUNT_PROMPT_DETAILS_CACHE_WRITE => 0,
        OpenInferenceAttributes::TOKEN_COUNT_COMPLETION_DETAILS_REASONING => 64,
    ]);
});

it('leaves the cache attributes off a provider that reports none', function (): void {
    // The control, and it is not cosmetic. Emitting 0 for an unreported field
    // would make "this provider has no prompt caching" indistinguishable from
    // "the cache never hit", which is a question somebody reads these to answer.
    // It also keeps every existing corpus row byte-identical: a turn with no
    // cache still exports exactly the five attributes it always did.
    config()->set('prism.telemetry.capture_content', false);

    [$exporter] = composedHarness();

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', 'a prompt');
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)
        ->toMatchArray([
            GenAiAttributes::USAGE_INPUT_TOKENS => 10,
            OpenInferenceAttributes::TOKEN_COUNT_PROMPT => 10,
            OpenInferenceAttributes::TOKEN_COUNT_TOTAL => 15,
        ])
        ->and($attributes)->not->toHaveKey(GenAiAttributes::USAGE_CACHE_READ_INPUT_TOKENS)
        ->and($attributes)->not->toHaveKey(GenAiAttributes::USAGE_REASONING_OUTPUT_TOKENS)
        ->and($attributes)->not->toHaveKey(OpenInferenceAttributes::TOKEN_COUNT_PROMPT_DETAILS_CACHE_READ);
});

it('exports the tool set with content capture OFF, which is where it is needed', function (): void {
    // prism-opentelemetry#2. A provider caches a prompt PREFIX and the tool
    // array is part of it, so a consumer explaining a cache miss needs to know
    // what the tool set was. They could not: tools live on the request, and
    // core nulls the request when capture is off -- the default, and therefore
    // the state every production span is exported in.
    //
    // Asserted together with the gate being shut, exactly as the rate-limit
    // test above is. A version of this that enabled capture would pass against
    // the defect.
    config()->set('prism.telemetry.capture_content', false);

    [$exporter] = composedHarness();

    $context = Telemetry::start(
        TelemetryOperation::Text,
        'anthropic',
        'claude-sonnet-4-5',
        new Request(model: 'claude-sonnet-4-5', tools: [
            (new Tool)->as('search')->for('Search the docs')->using(fn (): string => 'ok'),
            (new Tool)->as('write')->for('Write a file')->using(fn (): string => 'ok'),
        ]),
    );
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)->toMatchArray([
        'llm.tools.0.tool.name' => 'search',
        'llm.tools.1.tool.name' => 'write',
    ]);

    expect($attributes['prism.tools.0.digest'])->toStartWith('sha256:')
        ->and($attributes['prism.tools.0.digest'])->not->toBe($attributes['prism.tools.1.digest']);

    // The content half stays behind the gate. Both halves of this matter: the
    // names arrived AND the descriptions did not.
    expect($attributes)
        ->not->toHaveKey('llm.tools.0.tool.description')
        ->and($attributes)->not->toHaveKey('llm.tools.0.tool.json_schema')
        ->and($attributes)->not->toHaveKey(OpenInferenceAttributes::INPUT_VALUE);
});

it('keeps the tools in the order they were sent, because a reorder is a cache miss', function (): void {
    // A provider caches the tools array AS SERIALISED, so the same tools in a
    // different order is a different prefix and a miss. The index in
    // `llm.tools.<i>` is what carries that, and sorting the list -- the reflex,
    // since a set feels more canonical -- would make exactly that case
    // invisible, in the reassuring direction.
    config()->set('prism.telemetry.capture_content', false);

    [$exporter] = composedHarness();

    $context = Telemetry::start(
        TelemetryOperation::Text,
        'anthropic',
        'claude-sonnet-4-5',
        new Request(model: 'claude-sonnet-4-5', tools: [
            (new Tool)->as('zebra')->for('Last alphabetically, first in the array')->using(fn (): string => 'ok'),
            (new Tool)->as('alpha')->for('First alphabetically, last in the array')->using(fn (): string => 'ok'),
        ]),
    );
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)->toMatchArray([
        'llm.tools.0.tool.name' => 'zebra',
        'llm.tools.1.tool.name' => 'alpha',
    ]);
});

it('adds the definitions only when capture is ON', function (): void {
    // The other half. A tool description is instructions to a model, so it
    // belongs behind the gate -- and the ungated half is unchanged by turning
    // the gate on, which is what makes them two answers to two questions
    // rather than one thing with a switch.
    config()->set('prism.telemetry.capture_content', true);

    [$exporter] = composedHarness();

    $context = Telemetry::start(
        TelemetryOperation::Text,
        'anthropic',
        'claude-sonnet-4-5',
        new Request(model: 'claude-sonnet-4-5', tools: [
            (new Tool)->as('search')->for('Search the docs')->using(fn (): string => 'ok'),
        ]),
    );
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    expect($attributes)->toMatchArray([
        'llm.tools.0.tool.name' => 'search',
        'llm.tools.0.tool.description' => 'Search the docs',
    ]);

    expect($attributes['llm.tools.0.tool.json_schema'])->toBeString();
});

it('writes no tool attributes for a generation with no tools', function (): void {
    // The control. Without it the tests above pass against code that writes a
    // tool attribute unconditionally, and every embeddings or image span in the
    // system would carry an empty one.
    config()->set('prism.telemetry.capture_content', false);

    [$exporter] = composedHarness();

    $context = Telemetry::start(TelemetryOperation::Text, 'anthropic', 'claude-sonnet-4-5', 'a prompt');
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(10, 5));

    $written = array_keys($exporter->getSpans()[0]->getAttributes()->toArray());

    expect(array_filter($written, fn (string $key): bool => str_starts_with($key, 'llm.tools.')))->toBe([])
        ->and(array_filter($written, fn (string $key): bool => str_starts_with($key, 'prism.tools.')))->toBe([]);
});

it('keeps usage and finish reason on a span offering many tools', function (): void {
    // REPORTED FROM PRODUCTION, and a regression this package introduced.
    //
    // The OpenTelemetry SDK caps a span at 128 attributes by default
    // (SpanLimits::DEFAULT_SPAN_ATTRIBUTE_COUNT_LIMIT) and drops the excess
    // SILENTLY. Tool attributes were written at onGenerationStarted, two per
    // tool ungated and four under capture, BEFORE anything else -- so a step
    // offering 31 tools filled the span before `applyInput` ran, and usage,
    // finish reason and output never landed at all.
    //
    // The consumer saw roots with a model and a tool list and nothing else, and
    // no error anywhere: "the generation looks unfinished" rather than "the
    // tail of the tool list is missing". An MCP client reaches 30 tools easily.
    //
    // The tools are written LAST now, so the budget goes first to the few
    // attributes people actually read and truncation costs the end of the tool
    // list instead of the outcome of the call.
    // Capture ON with 31 tools is the consumer's measured case: four attributes
    // per tool is 124, and the base attributes finish the budget before
    // anything about the OUTCOME of the call gets written.
    config()->set('prism.telemetry.capture_content', true);

    [$exporter] = composedHarness();

    $tools = [];

    for ($i = 0; $i < 31; $i++) {
        $tools[] = (new Tool)->as('tool_'.$i)->for('Tool number '.$i)->using(fn (): string => 'ok');
    }

    $context = Telemetry::start(
        TelemetryOperation::Text,
        'anthropic',
        'claude-sonnet-4-5',
        new Request(model: 'claude-sonnet-4-5', tools: $tools),
    );
    Telemetry::completed($context, composedResponse(), FinishReason::Stop, new Usage(922, 210, cacheReadInputTokens: 34_678));

    $attributes = $exporter->getSpans()[0]->getAttributes()->toArray();

    // The attributes somebody reads to answer "what did this cost" and "did it
    // finish" must survive a long tool list.
    expect($attributes)->toMatchArray([
        GenAiAttributes::USAGE_INPUT_TOKENS => 35_600,
        GenAiAttributes::USAGE_OUTPUT_TOKENS => 210,
        GenAiAttributes::USAGE_CACHE_READ_INPUT_TOKENS => 34_678,
        OpenInferenceAttributes::TOKEN_COUNT_TOTAL => 35_810,
    ]);

    expect($attributes)->toHaveKey(GenAiAttributes::RATE_LIMIT_BUCKETS)
        ->and($attributes['gen_ai.response.finish_reasons'])->toBe(['Stop']);

    // And the first tool is still there, so this is not passing by dropping the
    // feature altogether.
    expect($attributes)->toHaveKey('llm.tools.0.tool.name');
});
