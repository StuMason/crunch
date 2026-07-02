<?php

declare(strict_types=1);

namespace App\Inference;

use App\Exceptions\VisionUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Python OCR sidecar (ocr-sidecar/) — the dedicated
 * document/UI-text OCR path (Tesseract + PaddleOCR), separate from the Florence-2
 * vision sidecar. Mirrors AsrClient/VisionClient.
 */
class OcrClient
{
    /**
     * OCR a local image with the given engine (`tesseract` | `paddle`).
     *
     * @param  int|null  $psm  Tesseract page-segmentation mode (6 = block, 7 = single line); ignored by paddle.
     */
    public function ocr(string $imagePath, string $engine, ?int $psm = null): string
    {
        $base = rtrim((string) config('crunch.ocr.url'), '/');
        $timeout = (int) config('crunch.ocr.timeout', 60);

        $contents = file_get_contents($imagePath);
        if ($contents === false) {
            throw new RuntimeException("Cannot read image file: {$imagePath}");
        }

        $fields = ['engine' => $engine];
        if ($psm !== null) {
            $fields['psm'] = (string) $psm;
        }

        try {
            $response = Http::timeout($timeout)
                ->asMultipart()
                ->attach('image', $contents, basename($imagePath))
                ->post("{$base}/ocr", $fields);
        } catch (ConnectionException $e) {
            $message = strtolower($e->getMessage());
            // cURL 28 = the sidecar didn't finish in time. Surface a 4xx (CF passes it
            // through, unlike a 5xx it would replace) telling the caller to shrink the input.
            if (str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
                throw new VisionUnavailableException(422, "OCR ({$engine}) couldn't process this image within {$timeout}s — downscale or crop a tighter region and retry.", $e);
            }

            throw new VisionUnavailableException(503, "OCR sidecar unreachable at {$base}.", $e);
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            // A bad/undecodable image is the caller's fault — pass the 422 straight through.
            if ($response->status() === 422) {
                throw new VisionUnavailableException(422, "OCR couldn't process this image: {$detail}");
            }

            throw new RuntimeException("OCR sidecar error ({$response->status()}): {$detail}");
        }

        return trim((string) $response->json('text'));
    }

    /**
     * Line-level OCR (tesseract): each reading-order line with its pixel box + mean confidence,
     * plus the joined text and the frame height. Boxes are in screen.mp4 pixel space — the same
     * space roll emits click/cursor coords in — so a click resolves to the line under it.
     *
     * @param  int|null  $minConf  drop words below this tesseract confidence before joining (sidecar default 40)
     * @return array{lines: list<array{text: string, conf: float, box: array<int, int>}>, text: string, image_height: int}
     */
    public function lines(string $imagePath, ?int $psm = null, ?int $minConf = null): array
    {
        $base = rtrim((string) config('crunch.ocr.url'), '/');
        $timeout = (int) config('crunch.ocr.timeout', 60);

        $contents = file_get_contents($imagePath);
        if ($contents === false) {
            throw new RuntimeException("Cannot read image file: {$imagePath}");
        }

        $fields = [];
        if ($psm !== null) {
            $fields['psm'] = (string) $psm;
        }
        if ($minConf !== null) {
            $fields['min_conf'] = (string) $minConf;
        }

        try {
            $response = Http::timeout($timeout)
                ->asMultipart()
                ->attach('image', $contents, basename($imagePath))
                ->post("{$base}/ocr/words", $fields);
        } catch (ConnectionException $e) {
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
                throw new VisionUnavailableException(422, "OCR (words) couldn't process this image within {$timeout}s — downscale or crop a tighter region and retry.", $e);
            }

            throw new VisionUnavailableException(503, "OCR sidecar unreachable at {$base}.", $e);
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();
            if ($response->status() === 422) {
                throw new VisionUnavailableException(422, "OCR couldn't process this image: {$detail}");
            }

            throw new RuntimeException("OCR sidecar error ({$response->status()}): {$detail}");
        }

        /** @var list<array{text: string, conf: float, box: array<int, int>}> $lines */
        $lines = (array) $response->json('lines', []);

        return [
            'lines' => $lines,
            'text' => trim((string) $response->json('text')),
            'image_height' => (int) $response->json('image_height', 0),
        ];
    }

    /**
     * Line-level OCR many local images concurrently — the pack pipeline's per-frame path.
     *
     * Frames go out in waves of $concurrency via {@see Http::pool}, sized to the sidecar's own
     * parallelism cap (OCR_MAX_PARALLEL) so requests run instead of queueing inside it. Per-frame
     * best-effort like the rest of the pipeline: a frame whose request fails (unreadable file,
     * connection error, non-2xx) is simply absent from the result, never fatal.
     *
     * @param  array<int|string, string>  $imagePaths  key => absolute image path
     * @param  (callable(int, int): void)|null  $onProgress  called after each wave with (done, total)
     * @return array<int|string, array{lines: list<array{text: string, conf: float, box: array<int, int>}>, text: string, image_height: int}> keyed as the input; failed frames omitted
     */
    public function linesMany(array $imagePaths, int $concurrency = 4, ?callable $onProgress = null): array
    {
        $base = rtrim((string) config('crunch.ocr.url'), '/');
        $timeout = (int) config('crunch.ocr.timeout', 60);

        $results = [];
        $total = count($imagePaths);
        $done = 0;

        foreach (array_chunk($imagePaths, max(1, $concurrency), preserve_keys: true) as $wave) {
            $responses = Http::pool(function (Pool $pool) use ($wave, $base, $timeout): array {
                $requests = [];
                foreach ($wave as $key => $path) {
                    $contents = @file_get_contents($path);
                    if ($contents === false) {
                        continue;
                    }
                    $requests[] = $pool->as((string) $key)
                        ->timeout($timeout)
                        ->asMultipart()
                        ->attach('image', $contents, basename($path))
                        ->post("{$base}/ocr/words");
                }

                return $requests;
            });

            foreach (array_keys($wave) as $key) {
                $response = $responses[$key] ?? null;
                // A pool entry is a ConnectionException instance when the request never completed.
                if (! $response instanceof Response || $response->failed()) {
                    continue;
                }

                /** @var list<array{text: string, conf: float, box: array<int, int>}> $lines */
                $lines = (array) $response->json('lines', []);

                $results[$key] = [
                    'lines' => $lines,
                    'text' => trim((string) $response->json('text')),
                    'image_height' => (int) $response->json('image_height', 0),
                ];
            }

            $done += count($wave);
            if ($onProgress !== null) {
                $onProgress($done, $total);
            }
        }

        return $results;
    }
}
