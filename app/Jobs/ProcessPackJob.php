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
        $job->update(['status' => InferenceJob::STATUS_PROCESSING, 'progress' => ['stage' => 'unpack']]);

        $workDir = storage_path("app/packs/{$this->jobId}-work");

        try {
            $packDir = $this->unpack($this->archivePath, $workDir);

            // Stage boundaries are minutes apart and OCR waves are seconds apart, so writing
            // every report straight to the row is cheap — and pollers of /jobs/{id} see live
            // progress instead of a bare "processing". Left as-is on failure (shows where it died).
            // rescue(): the write is best-effort like the pipeline it reports on — a transient
            // SQLite lock on a cosmetic update must never sink a minutes-long, tries=1 run.
            $result = $action->handle(
                $packDir,
                $this->packId,
                function (array $progress) use ($job): void {
                    rescue(fn () => $job->update(['progress' => $progress]), report: false);
                },
            );

            $job->update([
                'status' => InferenceJob::STATUS_COMPLETED,
                'result' => $result,
                'progress' => null,
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

        $this->assertSafeArchive($archivePath);

        $result = Process::timeout(300)->run(['tar', '-xf', $archivePath, '-C', $workDir]);
        if (! $result->successful()) {
            throw new RuntimeException('Could not unpack roll pack archive: '.trim($result->errorOutput()));
        }

        // Belt-and-braces: even with the pre-flight check, refuse to process a tree that contains
        // any symlink (it could point ffmpeg/OCR at an arbitrary file on the host).
        if (trim(Process::run(['find', $workDir, '-type', 'l'])->output()) !== '') {
            throw new RuntimeException('Pack archive expanded to symlinks; refusing to process.');
        }

        $root = realpath($workDir);
        foreach ([$workDir.'/manifest.json', ...glob($workDir.'/*/manifest.json') ?: []] as $manifest) {
            $real = realpath($manifest);
            if ($real !== false && $root !== false && str_starts_with($real, $root.'/')) {
                return dirname($real);
            }
        }

        throw new RuntimeException('No manifest.json found in the uploaded pack archive.');
    }

    /**
     * Reject a hostile archive BEFORE extracting it: no absolute paths, no `..` traversal, and
     * no symlink/hardlink entries — any of which could write or read outside the work directory.
     */
    private function assertSafeArchive(string $archivePath): void
    {
        $listing = Process::timeout(60)->run(['tar', '-tvf', $archivePath]);
        if (! $listing->successful()) {
            throw new RuntimeException('Could not read the pack archive listing.');
        }

        foreach (preg_split('/\R/', $listing->output()) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (in_array($line[0] ?? '-', ['l', 'h'], true)) {
                throw new RuntimeException('Pack archive contains a link entry; refusing to process.');
            }
        }

        foreach (preg_split('/\R/', Process::timeout(60)->run(['tar', '-tf', $archivePath])->output()) ?: [] as $name) {
            $name = trim($name);
            if ($name !== '' && (str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name))) {
                throw new RuntimeException('Pack archive contains an unsafe path; refusing to process.');
            }
        }
    }

    private function cleanup(string $dir): void
    {
        if (is_dir($dir)) {
            Process::run(['rm', '-rf', $dir]);
        }
    }
}
