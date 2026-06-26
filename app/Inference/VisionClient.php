<?php

declare(strict_types=1);

namespace App\Inference;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Python vision sidecar (vision-sidecar/).
 *
 * Captioning is the other capability that lives outside the PHP/ONNX core: the
 * image-to-text pipeline caps at vit-gpt2, so Florence-2-base runs in Python and
 * Crunch reaches it over the internal network. Mirrors AsrClient.
 */
class VisionClient
{
    /**
     * Caption a local image via the sidecar.
     *
     * @param  string  $detail  Caption verbosity: `normal`, `detailed`, or `more`.
     * @return array{model: string, task: string, caption: string}
     */
    public function caption(string $imagePath, string $detail = 'normal'): array
    {
        $base = rtrim((string) config('crunch.vision.url'), '/');

        $contents = file_get_contents($imagePath);
        if ($contents === false) {
            throw new RuntimeException("Cannot read image file: {$imagePath}");
        }

        try {
            $response = Http::timeout((int) config('crunch.vision.timeout', 120))
                ->asMultipart()
                ->attach('image', $contents, basename($imagePath))
                ->post("{$base}/caption", ['detail' => $detail]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Vision sidecar unreachable at {$base}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            throw new RuntimeException("Vision sidecar error ({$response->status()}): {$detail}");
        }

        /** @var array{model: string, task: string, caption: string} $json */
        $json = $response->json();

        return $json;
    }
}
