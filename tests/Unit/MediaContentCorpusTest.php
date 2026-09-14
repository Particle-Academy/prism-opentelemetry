<?php

declare(strict_types=1);

use Prism\OpenTelemetry\Support\MediaContent;

/**
 * The cross-language media-content corpus from `prism-parity`.
 *
 * This package is the reference, so this proves the corpus still matches the
 * code it records: which media shapes are recognised, and the size reported for
 * their bytes.
 */
function mediaContentCorpus(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/opentelemetry-media-content.json'), true, 512, JSON_THROW_ON_ERROR);
}

it('is the whole suite, not a subset someone trimmed to green', function (): void {
    expect(mediaContentCorpus()['cases'])->toHaveCount(15);
});

it('produces the recorded output for every row', function (array $case): void {
    $output = json_encode(MediaContent::withoutBytes($case['input']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    expect($output)->toBe($case['output']['php']);
})->with(fn (): array => array_map(fn (array $case): array => [$case], mediaContentCorpus()['cases']));

it('agrees with both ports on every row', function (): void {
    foreach (mediaContentCorpus()['cases'] as $case) {
        expect([$case['output']['ts'], $case['output']['py']])->toBe([$case['output']['php'], $case['output']['php']], $case['id'])
            ->and($case['agrees'])->toBeTrue();
    }
});
