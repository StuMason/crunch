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
 * Frames are capped at a max edge, but kept near-native: tesseract's accuracy on dense UI text
 * collapses when a 2560-wide screen capture is downscaled (measured ~1460 readable chars at
 * native vs ~570 chars of noise at 1280), so the cap only protects against oversized 4K+ sources
 * rather than shrinking a normal capture. (The aggressive downscale in crunch #11 was for the
 * Florence-2 *vision* sidecar, which is a different, generative path — not this OCR one.)
 *
 * One ffmpeg invocation per frame with a fast pre-input seek; a frame that fails to extract is
 * skipped (logged by the caller) rather than sinking the whole job.
 */
class FrameExtractor
{
    public function __construct(
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly int $maxEdge = 2560,
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
