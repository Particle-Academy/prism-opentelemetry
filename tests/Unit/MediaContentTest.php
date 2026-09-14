<?php

declare(strict_types=1);

use Prism\OpenTelemetry\Support\MediaContent;

it('replaces a media part\'s bytes with their size, and keeps what identifies it', function (): void {
    $part = ['kind' => 'image', 'url' => null, 'base64' => base64_encode('PNGBYTES'), 'mime_type' => 'image/png', 'file_id' => null, 'filename' => 'a.png'];

    expect(MediaContent::withoutBytes($part))->toBe([
        'kind' => 'image', 'url' => null, 'base64' => null, 'mime_type' => 'image/png', 'file_id' => null, 'filename' => 'a.png', 'omitted_bytes' => 8,
    ]);
});

it('finds media however deep it sits in the captured payload', function (): void {
    $payload = ['messages' => [[
        'type' => 'user',
        'content' => 'Look',
        'additional_content' => [
            ['kind' => 'document', 'base64' => base64_encode('the whole document'), 'mime_type' => 'text/plain', 'document_title' => 'Brief', 'chunks' => null],
            ['text' => 'Look'],
        ],
    ]]];

    $out = MediaContent::withoutBytes($payload);

    expect($out['messages'][0]['additional_content'][0]['base64'])->toBeNull()
        ->and($out['messages'][0]['additional_content'][0]['omitted_bytes'])->toBe(18)
        ->and($out['messages'][0]['additional_content'][1])->toBe(['text' => 'Look'])
        ->and(json_encode($out))->not->toContain(base64_encode('the whole document'));
});

it('recognises the stored shape from before prism v0.120.0, which named no kind', function (): void {
    $legacy = ['url' => null, 'base64' => base64_encode('PNGBYTES'), 'mime_type' => 'image/png', 'file_id' => null, 'local_path' => null, 'storage_path' => null, 'filename' => null];

    expect(MediaContent::withoutBytes($legacy)['base64'])->toBeNull();
});

it('leaves alone what is not a media part, even with a key called base64', function (array $value): void {
    // A tool's own result is the tool's business. Only the media SHAPE is
    // recognised, so an arbitrary payload is never rewritten on a guess.
    expect(MediaContent::withoutBytes($value))->toBe($value);
})->with([
    'a tool result' => [['base64' => 'aGVsbG8=', 'encoding' => 'base64']],
    'an unknown kind' => [['kind' => 'spreadsheet', 'base64' => 'aGVsbG8=', 'mime_type' => 'text/csv']],
    'a url part with no bytes' => [['kind' => 'image', 'url' => 'https://example.com/a.png', 'base64' => null, 'mime_type' => null]],
    'empty bytes' => [['kind' => 'image', 'base64' => '', 'mime_type' => 'image/png']],
]);
