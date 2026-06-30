<?php

declare(strict_types=1);

namespace App\Inference;

use App\Exceptions\VisionUnavailableException;
use Illuminate\Http\Client\ConnectionException;
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
     * Word-level OCR (tesseract): every word with its pixel box + confidence, plus the
     * reassembled reading-order text. Boxes are in screen.mp4 pixel space — the same space roll
     * emits click/cursor coords in — so a click can be resolved to the exact word under it.
     *
     * @param  int|null  $minConf  drop words below this tesseract confidence (sidecar default 50)
     * @return array{words: list<array{text: string, conf: float, box: array<int, int>, line: array<int, int>}>, text: string}
     */
    public function words(string $imagePath, ?int $psm = null, ?int $minConf = null): array
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

        /** @var list<array{text: string, conf: float, box: array<int, int>, line: array<int, int>}> $words */
        $words = (array) $response->json('words', []);

        return ['words' => $words, 'text' => trim((string) $response->json('text'))];
    }
}
