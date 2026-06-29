<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\InferenceJob;

/**
 * Shapes an InferenceJob into the public polling envelope returned by `/jobs/{id}` (and by
 * the 202 responses of the async submit endpoints — `/transcribe`, `/ocr/batch`).
 *
 * The envelope is identical across job types; only the `result` payload differs, so it's
 * normalised per `type` here. Keeping this in one place means every job type polls the same
 * way and the OpenAPI schema (Scramble) has a single source of truth for the shape.
 */
class JobPresenter
{
    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     status: string,
     *     model: string|null,
     *     result: array<string, mixed>|null,
     *     error: string|null,
     *     poll_url: string,
     *     created_at: string|null,
     *     completed_at: string|null
     * }
     */
    public static function present(InferenceJob $job): array
    {
        return [
            'id' => $job->uid,
            'type' => $job->type,
            'status' => $job->status,
            'model' => $job->model,
            'result' => self::result($job),
            'error' => $job->error,
            'poll_url' => url("/jobs/{$job->uid}"),
            'created_at' => $job->created_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
        ];
    }

    /**
     * The job's output once completed (null until then). The `result` column is untyped
     * JSON, so we normalise it into the per-type shape here — both to harden against a
     * malformed row and so the generated OpenAPI reflects the real structure.
     *
     * @return array<string, mixed>|null
     */
    private static function result(InferenceJob $job): ?array
    {
        if ($job->result === null) {
            return null;
        }

        return match ($job->type) {
            'ocr-batch' => self::ocrBatchResult($job),
            default => self::transcriptionResult($job),
        };
    }

    /**
     * Transcription payload — OpenAI Whisper `verbose_json`: the full `text`, plus `words`
     * with start/end timestamps, `duration`, detected `language` and `task`.
     *
     * @return array{task: string, language: string|null, duration: float|null, text: string, words: list<array{word: string, start: float, end: float}>}
     */
    private static function transcriptionResult(InferenceJob $job): array
    {
        $result = (array) $job->result;

        $words = [];
        foreach ((array) ($result['words'] ?? []) as $word) {
            if (! is_array($word)) {
                continue;
            }
            $words[] = [
                'word' => (string) ($word['word'] ?? ''),
                'start' => (float) ($word['start'] ?? 0),
                'end' => (float) ($word['end'] ?? 0),
            ];
        }

        return [
            'task' => (string) ($result['task'] ?? 'transcribe'),
            'language' => isset($result['language']) ? (string) $result['language'] : null,
            'duration' => isset($result['duration']) ? (float) $result['duration'] : null,
            'text' => (string) ($result['text'] ?? ''),
            'words' => $words,
        ];
    }

    /**
     * Batch-OCR payload — one entry per submitted crop, in submission order. Each carries the
     * caller's `box` echoed back verbatim (e.g. `[x, y, w, h]`), the OCR'd `text`, and an
     * `error` that is non-null only for the crops that failed (a single bad crop never sinks
     * the pack).
     *
     * @return array{count: int, results: list<array{box: mixed, text: string|null, error: string|null}>}
     */
    private static function ocrBatchResult(InferenceJob $job): array
    {
        $results = [];
        foreach ((array) (((array) $job->result)['results'] ?? []) as $item) {
            $item = (array) $item;
            $results[] = [
                'box' => $item['box'] ?? null,
                'text' => isset($item['text']) ? (string) $item['text'] : null,
                'error' => isset($item['error']) ? (string) $item['error'] : null,
            ];
        }

        return ['count' => count($results), 'results' => $results];
    }
}
