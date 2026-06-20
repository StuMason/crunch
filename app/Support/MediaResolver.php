<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolve a media input (image/audio) from a request into something a
 * transformers-php pipeline can consume: a validated http(s) URL (pipelines fetch
 * images directly) or a local file path (required for audio, base64, uploads).
 *
 * Accepts, in order: `url`, a base64 `$field`, or a multipart-uploaded `$field`.
 *
 * URL inputs are guarded against SSRF: http(s) only, and the host must not resolve
 * to a private/reserved address (the box runs internal-only services).
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
            self::assertSafeUrl($url);

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

    /**
     * Reject non-http(s) schemes and URLs that resolve to private/reserved IPs (SSRF guard).
     */
    private static function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            abort(422, 'url must be an absolute http(s) URL.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            abort(422, 'Could not resolve url host.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                abort(422, 'url must not resolve to a private or reserved address.');
            }
        }
    }

    private static function download(string $url): string
    {
        // Follow redirects (CDNs commonly 301), but re-validate every hop against the
        // SSRF guard so a public URL can't bounce to an internal host. http(s) only;
        // the HTTP client never honours file:// / php:// like file_get_contents would.
        $response = Http::withOptions([
            'allow_redirects' => [
                'max' => 5,
                'strict' => true,
                'referer' => false,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri): void {
                    self::assertSafeUrl((string) $uri);
                },
            ],
        ])->timeout(20)->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Could not fetch media from URL (HTTP {$response->status()}).");
        }

        return self::writeTemp($response->body());
    }

    private static function writeTemp(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crunch_');
        file_put_contents($path, $contents);

        return $path;
    }
}
