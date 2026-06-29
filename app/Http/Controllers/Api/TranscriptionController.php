<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranscribeJob;
use App\Models\InferenceJob;
use App\Support\JobPresenter;
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

        return response()->json(JobPresenter::present($job), 202);
    }
}
