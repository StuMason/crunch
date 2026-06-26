<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscribeJob;
use App\Models\InferenceJob;
use App\Support\MediaResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TranscriptionController extends Controller
{
    /**
     * Transcribe audio to text (async)
     *
     * Speech-to-text with Whisper. Because audio can be long, this runs in the
     * background: you submit the audio and immediately get a **job** back, then poll
     * `GET /jobs/{id}` until its `status` is `completed` and read `result.text`.
     *
     * **Send the audio one of three ways:**
     * - `application/json` with `"url"`: a public audio URL — `{"url": "https://…/clip.wav"}`
     * - `application/json` with `"audio"`: the file as a base64 string — `{"audio": "<base64>"}`
     * - `multipart/form-data`: upload the file — `curl -F audio=@clip.wav`
     *
     * **Get back:** `202 Accepted` with a job — note its `id` and poll it. When the job
     * completes, `result` is the OpenAI Whisper `verbose_json` shape: `text` (the full
     * transcript), `words` (each spoken word with `start`/`end` timestamps in seconds —
     * verbatim, fillers kept), `duration`, detected `language` and `task`.
     */
    public function transcribe(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['sometimes', 'string'],   // public audio URL
            'audio' => ['sometimes'],           // base64 string or uploaded file
        ]);

        [$audioPath] = MediaResolver::resolve($request, 'audio', mustBeLocal: true);

        $job = InferenceJob::create([
            'type' => 'transcribe',
            'status' => InferenceJob::STATUS_QUEUED,
            'model' => config('crunch.models.transcribe.model'),
        ]);

        TranscribeJob::dispatch($job->id, $audioPath);

        return response()->json($this->present($job), 202);
    }

    /**
     * Check an async job
     *
     * Poll this with the `id` you got from `/transcribe`. While it's running `status`
     * is `queued` or `processing`; when done it's `completed` and `result` holds the
     * output (e.g. `result.text` for transcription). On failure, `status` is `failed`
     * with an `error`.
     */
    public function show(InferenceJob $job): JsonResponse
    {
        return response()->json($this->present($job));
    }

    /**
     * @return array{
     *     id: string,
     *     type: string,
     *     status: string,
     *     model: string|null,
     *     result: array{task: string, language: string|null, duration: float|null, text: string, words: list<array{word: string, start: float, end: float}>}|null,
     *     error: string|null,
     *     poll_url: string,
     *     created_at: string|null,
     *     completed_at: string|null
     * }
     */
    private function present(InferenceJob $job): array
    {
        return [
            'id' => $job->uid,
            'type' => $job->type,
            'status' => $job->status,
            'model' => $job->model,
            'result' => $this->result($job),
            'error' => $job->error,
            'poll_url' => url("/jobs/{$job->uid}"),
            'created_at' => $job->created_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
        ];
    }

    /**
     * The transcription payload once the job has completed (null until then). The `result`
     * column is an untyped JSON array, so we normalise it into the OpenAI `verbose_json` shape
     * here — both to harden against a malformed row and so the OpenAPI schema reflects the real
     * structure instead of a bare object.
     *
     * @return array{task: string, language: string|null, duration: float|null, text: string, words: list<array{word: string, start: float, end: float}>}|null
     */
    private function result(InferenceJob $job): ?array
    {
        $result = $job->result;

        if ($result === null) {
            return null;
        }

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
}
