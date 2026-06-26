<?php

declare(strict_types=1);

namespace App\Inference;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Python ASR sidecar (asr-sidecar/).
 *
 * Verbatim transcription is the one capability that lives outside the PHP/ONNX
 * core; this is the seam between the two. Posts the audio file and returns the
 * sidecar's raw result — text, word-level timestamps, duration — unmodified.
 */
class AsrClient
{
    /**
     * Transcribe a local audio/video file via the sidecar.
     *
     * @return array{task: string, language: string, duration: float, text: string, words: list<array{word: string, start: float, end: float}>, model: string, infer_secs: float}
     */
    public function transcribe(string $audioPath): array
    {
        $base = rtrim((string) config('crunch.asr.url'), '/');

        $contents = file_get_contents($audioPath);
        if ($contents === false) {
            throw new RuntimeException("Cannot read audio file: {$audioPath}");
        }

        try {
            $response = Http::timeout((int) config('crunch.asr.timeout', 600))
                ->asMultipart()
                ->attach('audio', $contents, basename($audioPath))
                ->post("{$base}/transcribe");
        } catch (ConnectionException $e) {
            throw new RuntimeException("ASR sidecar unreachable at {$base}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?? $response->body();

            throw new RuntimeException("ASR sidecar error ({$response->status()}): {$detail}");
        }

        /** @var array{task: string, language: string, duration: float, text: string, words: list<array{word: string, start: float, end: float}>, model: string, infer_secs: float} $json */
        $json = $response->json();

        return $json;
    }
}
