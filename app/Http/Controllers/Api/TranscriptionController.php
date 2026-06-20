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
     * Queue an async transcription: POST /transcribe.
     * Audio via `url`, base64 `audio`, or uploaded `audio`. Returns a job to poll.
     */
    public function transcribe(Request $request): JsonResponse
    {
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
     * Poll an async job: GET /jobs/{job}.
     */
    public function show(InferenceJob $job): JsonResponse
    {
        return response()->json($this->present($job));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(InferenceJob $job): array
    {
        return [
            'id' => $job->uid,
            'type' => $job->type,
            'status' => $job->status,
            'model' => $job->model,
            'result' => $job->result,
            'error' => $job->error,
            'poll_url' => url("/jobs/{$job->uid}"),
            'created_at' => $job->created_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
        ];
    }
}
