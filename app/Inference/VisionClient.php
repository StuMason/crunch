<?php

declare(strict_types=1);

namespace App\Inference;

use App\Exceptions\VisionUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin HTTP client for the Python vision sidecar (vision-sidecar/).
 *
 * Florence-2-base is a multi-task vision model that lives outside the PHP/ONNX core
 * (the image-to-text pipeline caps at vit-gpt2). One warm model backs captioning, OCR
 * and object detection — each is just a different task token. Mirrors AsrClient.
 */
class VisionClient
{
    /**
     * Caption a local image.
     *
     * @param  string  $detail  Caption verbosity: `normal`, `detailed`, or `more`.
     * @return array{model: string, task: string, caption: string}
     */
    public function caption(string $imagePath, string $detail = 'normal'): array
    {
        /** @var array{model: string, task: string, caption: string} $json */
        $json = $this->send($imagePath, ['detail' => $detail]);

        return $json;
    }

    /**
     * Run an arbitrary Florence-2 task (e.g. `<OCR>`, `<OD>`) on a local image. The shape
     * of `result` depends on the task — a string for OCR, `{bboxes, labels}` for detection.
     *
     * @return array{model: string, task: string, result: mixed}
     */
    public function task(string $imagePath, string $task): array
    {
        /** @var array{model: string, task: string, result: mixed} $json */
        $json = $this->send($imagePath, ['task' => $task]);

        return $json;
    }

    /**
     * POST the image (plus the given form fields) to the sidecar and return its JSON.
     *
     * @param  array<string, string>  $fields
     * @return array<string, mixed>
     */
    private function send(string $imagePath, array $fields): array
    {
        $base = rtrim((string) config('crunch.vision.url'), '/');

        $contents = file_get_contents($imagePath);
        if ($contents === false) {
            throw new RuntimeException("Cannot read image file: {$imagePath}");
        }

        $timeout = (int) config('crunch.vision.timeout', 120);

        try {
            $response = Http::timeout($timeout)
                ->asMultipart()
                ->attach('image', $contents, basename($imagePath))
                ->post("{$base}/caption", $fields);
        } catch (ConnectionException $e) {
            // cURL 28 = the sidecar didn't answer within the deadline — a too-large or
            // too-dense image (issues #11/#12), distinct from the sidecar being down.
            // Surface 504 so callers can downscale/crop and retry, not treat it as a 500.
            $message = strtolower($e->getMessage());
            if (str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
                throw new VisionUnavailableException(504, "Vision timed out after {$timeout}s — the image is too large or dense. Downscale (longest edge ≤ ~1024px) or crop, then retry.", $e);
            }

            throw new VisionUnavailableException(503, "Vision sidecar unreachable at {$base}.", $e);
        }

        if ($response->failed()) {
            $error = $response->json('detail') ?? $response->body();

            // A timeout/overload reported by the sidecar itself keeps its status (504/503).
            if (in_array($response->status(), [503, 504], true)) {
                throw new VisionUnavailableException($response->status(), "Vision unavailable: {$error}");
            }

            throw new RuntimeException("Vision sidecar error ({$response->status()}): {$error}");
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }
}
