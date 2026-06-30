<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Roll\ProcessPack;
use App\Models\InferenceJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Processes an uploaded roll pack archive off the request lifecycle: unpack it, run the
 * {@see ProcessPack} action (frames → OCR → transcript → join), and record the resulting
 * crunch.json on the InferenceJob for poll-based retrieval. Controller → Job → Action,
 * mirroring OcrBatchJob/TranscribeJob.
 *
 * Generous timeout: one pack means a transcribe (minutes on CPU) plus an OCR per sampled
 * frame, all sequential on the single queue worker. One try — re-running would redo the
 * whole expensive pipeline.
 */
class ProcessPackJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $jobId,
        public string $archivePath,
        public string $packId,
    ) {}

    public function handle(ProcessPack $action): void
    {
        $job = InferenceJob::findOrFail($this->jobId);
        $job->update(['status' => InferenceJob::STATUS_PROCESSING]);

        $workDir = storage_path("app/packs/{$this->jobId}-work");

        try {
            $packDir = $this->unpack($this->archivePath, $workDir);
            $result = $action->handle($packDir, $this->packId);

            $job->update([
                'status' => InferenceJob::STATUS_COMPLETED,
                'result' => $result,
                'completed_at' => now(),
            ]);
        } finally {
            $this->cleanup($workDir);
            @unlink($this->archivePath);
        }
    }

    public function failed(Throwable $e): void
    {
        InferenceJob::find($this->jobId)?->update([
            'status' => InferenceJob::STATUS_FAILED,
            'error' => $e->getMessage(),
        ]);

        $this->cleanup(storage_path("app/packs/{$this->jobId}-work"));
        @unlink($this->archivePath);
    }

    /**
     * Unpack the archive (tar/tar.gz/tgz — GNU tar auto-detects compression) and locate the
     * directory the manifest lives in, tolerating either a flat archive or a `rec-xxx/` wrapper.
     */
    private function unpack(string $archivePath, string $workDir): string
    {
        @mkdir($workDir, 0775, true);

        $result = Process::timeout(300)->run(['tar', '-xf', $archivePath, '-C', $workDir]);
        if (! $result->successful()) {
            throw new RuntimeException('Could not unpack roll pack archive: '.trim($result->errorOutput()));
        }

        foreach ([$workDir.'/manifest.json', ...glob($workDir.'/*/manifest.json') ?: []] as $manifest) {
            if (is_file($manifest)) {
                return dirname($manifest);
            }
        }

        throw new RuntimeException('No manifest.json found in the uploaded pack archive.');
    }

    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            Process::run(['rm', '-rf', $dir]);
        }
    }
}
