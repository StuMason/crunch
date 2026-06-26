<?php

declare(strict_types=1);

namespace App\Inference;

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

        try {
            $response = Http::timeout((int) config('crunch.vision.timeout', 120))
                ->asMultipart()
                ->attach('image', $contents, basename($imagePath))
                ->post("{$base}/caption", $fields);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Vision sidecar unreachable at {$base}: {$e->getMessage()}", previous: $e);
        }

        if ($response->failed()) {
            $error = $response->json('detail') ?? $response->body();

            throw new RuntimeException("Vision sidecar error ({$response->status()}): {$error}");
        }

        /** @var array<string, mixed> $json */
        $json = $response->json();

        return $json;
    }
}
