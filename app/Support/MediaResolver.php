<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * Resolve a media input (image/audio) from a request into something a
 * transformers-php pipeline can consume: a URL (pipelines fetch images directly)
 * or a local file path (required for audio, and for base64/uploaded inputs).
 *
 * Accepts, in order: `url`, a base64 `$field`, or a multipart-uploaded `$field`.
 */
class MediaResolver
{
    /**
     * @return array{0: string, 1: bool} [pathOrUrl, isTempFile] — delete the path if isTempFile.
     */
    public static function resolve(Request $request, string $field, bool $mustBeLocal = false): array
    {
        if ($request->filled('url')) {
            $url = (string) $request->input('url');

            return $mustBeLocal ? [self::download($url), true] : [$url, false];
        }

        if ($request->filled($field) && is_string($request->input($field))) {
            $raw = preg_replace('#^data:[^;]+;base64,#', '', (string) $request->input($field));

            return [self::writeTemp((string) base64_decode($raw, true)), true];
        }

        if ($request->hasFile($field)) {
            $realPath = (string) $request->file($field)->getRealPath();

            // The PHP upload temp file is removed at request end — copy it to our own
            // temp when the consumer (e.g. an async job) needs it to outlive the request.
            return $mustBeLocal
                ? [self::writeTemp((string) file_get_contents($realPath)), true]
                : [$realPath, false];
        }

        abort(422, "Provide a '{$field}' (base64 string or uploaded file) or a 'url'.");
    }

    private static function download(string $url): string
    {
        $contents = @file_get_contents($url);

        if ($contents === false) {
            throw new RuntimeException("Could not fetch media from URL: {$url}");
        }

        return self::writeTemp($contents);
    }

    private static function writeTemp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crunch_');
        file_put_contents($path, $contents);

        return $path;
    }
}
