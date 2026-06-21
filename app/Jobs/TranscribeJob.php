<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Inference\Transcribe;
use App\Models\InferenceJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Run a transcription off the request lifecycle, recording the outcome on the
 * InferenceJob row for poll-based retrieval. Controller → Job → Action.
 */
class TranscribeJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 3;

    /**
     * Wait between retries — a transient blip (slow model load, a momentary FFI
     * hiccup) shouldn't burn all attempts at once.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 15];
    }

    public function __construct(
        public int $jobId,
        public string $audioPath,
    ) {}

    public function handle(Transcribe $transcribe): void
    {
        $job = InferenceJob::findOrFail($this->jobId);
        $job->update(['status' => InferenceJob::STATUS_PROCESSING]);

        // Do NOT delete the audio here on failure: with tries > 1 the file must survive
        // between attempts. Cleanup happens on success below and in failed() (terminal).
        $text = $transcribe->handle($this->audioPath);

        $job->update([
            'status' => InferenceJob::STATUS_COMPLETED,
            'result' => ['text' => $text],
            'completed_at' => now(),
        ]);

        @unlink($this->audioPath);
    }

    public function failed(Throwable $e): void
    {
        InferenceJob::find($this->jobId)?->update([
            'status' => InferenceJob::STATUS_FAILED,
            'error' => $e->getMessage(),
        ]);

        @unlink($this->audioPath);
    }
}
