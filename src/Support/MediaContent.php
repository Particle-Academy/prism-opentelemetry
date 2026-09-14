<?php

declare(strict_types=1);

namespace Prism\OpenTelemetry\Support;

/**
 * Captured content with the media bytes taken out.
 *
 * Content capture exports what a model was sent and said. Since prism
 * v0.120.0 a message's stored form carries each attachment's bytes, so a span
 * would carry an uploaded image or document with it. Content capture was never
 * understood to include that: "content" had meant text. So by default a media
 * part keeps what identifies it (its kind, mime type, file id, filename, url)
 * and loses its bytes, replaced by their size. `prism.telemetry.capture_media`
 * sends the bytes, still cut to `content_max_length`.
 *
 * A media part is recognised by its STORED SHAPE (a `kind` of image, audio,
 * video or document beside a `base64` key), not by class, because what arrives
 * here is already an array. The TypeScript and Python bridges apply the same
 * rule to the same shape, pinned by prism-parity's
 * `opentelemetry-media-content` corpus.
 */
final class MediaContent
{
    private const KINDS = ['image', 'audio', 'video', 'document'];

    public static function withoutBytes(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (self::isMedia($value) && is_string($value['base64']) && $value['base64'] !== '') {
            // The DECODED size, which is what a person means by the size of a
            // file. Lenient, as the other two bridges decode it.
            $value['omitted_bytes'] = strlen(base64_decode($value['base64']));
            $value['base64'] = null;
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::withoutBytes($item);
            }
        }

        return $value;
    }

    /**
     * Either stored shape of a media part.
     *
     * From prism v0.120.0 a part names its `kind`. Before that it carried no kind
     * and was recognisable only by its keys (`base64` beside `mime_type` and
     * `file_id`). This bridge supports older prism releases too, and a part in
     * the older shape carries bytes just the same.
     *
     * @param  array<array-key, mixed>  $value
     */
    private static function isMedia(array $value): bool
    {
        if (! array_key_exists('base64', $value)) {
            return false;
        }

        if (isset($value['kind'])) {
            return in_array($value['kind'], self::KINDS, true);
        }

        return array_key_exists('mime_type', $value) && array_key_exists('file_id', $value);
    }
}
