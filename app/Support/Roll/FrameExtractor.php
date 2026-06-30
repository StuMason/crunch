<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\Pack;
use App\DataTransferObjects\Roll\PackManifest;
use Illuminate\Support\Facades\Process;

/**
 * Pulls individual screen frames out of `screen.mp4` at the timestamps {@see FrameSampler}
 * chose. The screen track is anchored at the pack's `t0`, so the seek for an event at `t_ms`
 * is simply `t_ms/1000` seconds in — {@see PackManifest::screenSecondsAt()}.
 *
 * Frames are downscaled to a max edge before they leave here — full 2560×1440 captures are
 * needlessly heavy for OCR and (per crunch #11) push the sidecars past their CPU/latency budget.
 * One ffmpeg invocation per frame with a fast pre-input seek; a frame that fails to extract is
 * skipped (logged by the caller) rather than sinking the whole job.
 */
class FrameExtractor
{
    public function __construct(
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly int $maxEdge = 1280,
    ) {}

    /**
     * @param  list<int>  $timesMs
     * @return array<int, string> t_ms => absolute path of the extracted JPEG (only successful frames)
     */
    public function extract(Pack $pack, array $timesMs, string $workDir): array
    {
        $screen = $pack->absolutePath($pack->manifest->screenFile);
        @mkdir($workDir, 0775, true);

        $frames = [];
        foreach ($timesMs as $tMs) {
            $seconds = $pack->manifest->screenSecondsAt($tMs);
            $out = rtrim($workDir, '/')."/frame_{$tMs}.jpg";

            $result = Process::run([
                $this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
                '-ss', sprintf('%.3f', $seconds),
                '-i', $screen,
                '-frames:v', '1',
                '-vf', "scale='min({$this->maxEdge},iw)':'-2'",
                $out,
            ]);

            if ($result->successful() && is_file($out) && filesize($out) > 0) {
                $frames[$tMs] = $out;
            }
        }

        return $frames;
    }
}
