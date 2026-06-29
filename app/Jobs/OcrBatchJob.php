<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Inference\Ocr;
use App\Models\InferenceJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * OCR a batch of crops off the request lifecycle, recording one result per crop on the
 * InferenceJob row for poll-based retrieval. Controller → Job → Action (mirrors TranscribeJob).
 *
 * Crops are OCR'd strictly one at a time: the queue runs a single worker and the vision
 * sidecar is itself serial, so the batch never exceeds the vision inflight cap — that's the
 * whole point of doing this async instead of letting a client fan out and eat 429s. Each
 * crop is isolated in its own try/catch so one un-OCR-able crop is reported as a per-crop
 * `error` rather than failing the entire pack.
 */
class OcrBatchJob implements ShouldQueue
{
    use Queueable;

    // Generously above the worst case (MAX_CROPS × a slow sidecar call) — the batch is
    // bounded at the controller, and crops run sequentially.
    public int $timeout = 1800;

    // Per-crop errors are captured in-loop, so a failed crop never throws. Retrying the whole
    // job would needlessly re-OCR every already-done crop, so we don't.
    public int $tries = 1;

    /**
     * @param  list<array{box: mixed, path: string}>  $crops
     */
    public function __construct(
        public int $jobId,
        public array $crops,
        public string $engine = 'tesseract',
        public ?int $psm = null,
    ) {}

    public function handle(Ocr $ocr): void
    {
        $job = InferenceJob::findOrFail($this->jobId);
        $job->update(['status' => InferenceJob::STATUS_PROCESSING]);

        $results = [];
        foreach ($this->crops as $crop) {
            try {
                $results[] = ['box' => $crop['box'], 'text' => $ocr->handle($crop['path'], $this->engine, $this->psm), 'error' => null];
            } catch (Throwable $e) {
                $results[] = ['box' => $crop['box'], 'text' => null, 'error' => $e->getMessage()];
            } finally {
                @unlink($crop['path']);
            }
        }

        $job->update([
            'status' => InferenceJob::STATUS_COMPLETED,
            'result' => ['count' => count($results), 'results' => $results],
            'completed_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        InferenceJob::find($this->jobId)?->update([
            'status' => InferenceJob::STATUS_FAILED,
            'error' => $e->getMessage(),
        ]);

        // Terminal: drop any crops not already cleaned up in handle()'s loop.
        foreach ($this->crops as $crop) {
            @unlink($crop['path']);
        }
    }
}
