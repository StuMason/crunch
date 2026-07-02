<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\Pack;
use App\DataTransferObjects\Roll\PackManifest;
use Illuminate\Process\Pool as ProcessPool;
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
 * Frames are written as lossless PNG, not JPEG: JPEG ringing artifacts around sharp anti-aliased
 * UI text measurably corrupt the OCR (a clean "x402" reads as "x42", and ringing invents stray
 * leading quotes) — proven side-by-side on a real pack frame. PNG is ~2x the temp-file size but
 * the frames are transient and the accuracy gain is large.
 *
 * One ffmpeg invocation per frame with a fast pre-input seek, run in bounded parallel waves
 * ({@see Process::pool}) — each is an independent seek + single-frame decode, so N at a time
 * divides extraction wall-time by ~N without any shared state. A frame that fails to extract
 * is skipped (logged by the caller) rather than sinking the whole job.
 */
class FrameExtractor
{
    public function __construct(
        private readonly string $ffmpeg = 'ffmpeg',
        private readonly int $maxEdge = 2560,
    ) {}

    /**
     * Extract screen frames at the given shared-clock timestamps. The screen track is anchored at
     * `t0`, so the seek is `t_ms/1000` — {@see PackManifest::screenSecondsAt()}.
     *
     * @param  list<int>  $timesMs
     * @return array<int, string> t_ms => absolute path of the extracted PNG (only successful frames)
     */
    public function extract(Pack $pack, array $timesMs, string $workDir, int $concurrency = 4): array
    {
        return $this->extractFrom(
            $pack->absolutePath($pack->manifest->screenFile),
            $timesMs,
            fn (int $tMs): float => $pack->manifest->screenSecondsAt($tMs),
            $workDir,
            concurrency: $concurrency,
        );
    }

    /**
     * Extract camera frames at the given shared-clock timestamps. The camera starts
     * `cameraSyncOffsetMs` after `t0`, so the seek is shifted — {@see PackManifest::cameraSecondsAt()}.
     *
     * @param  list<int>  $timesMs
     * @return array<int, string> t_ms => absolute path of the extracted PNG (only successful frames)
     */
    public function extractCamera(Pack $pack, array $timesMs, string $workDir, int $concurrency = 4): array
    {
        if ($pack->manifest->cameraFile === null) {
            return [];
        }

        return $this->extractFrom(
            $pack->absolutePath($pack->manifest->cameraFile),
            $timesMs,
            fn (int $tMs): float => $pack->manifest->cameraSecondsAt($tMs),
            $workDir,
            prefix: 'cam',
            concurrency: $concurrency,
        );
    }

    /**
     * One ffmpeg invocation per timestamp against a single media file, seeking with the supplied
     * clock function. A frame that fails to extract is skipped rather than sinking the job.
     *
     * @param  list<int>  $timesMs
     * @param  callable(int): float  $secondsAt  shared-clock t_ms => seconds to seek into this file
     * @param  int  $concurrency  how many ffmpeg processes run at once (one wave)
     * @return array<int, string> t_ms => absolute PNG path (only successful frames)
     */
    public function extractFrom(string $mediaPath, array $timesMs, callable $secondsAt, string $workDir, string $prefix = 'frame', int $concurrency = 4): array
    {
        @mkdir($workDir, 0775, true);

        $frames = [];
        foreach (array_chunk($timesMs, max(1, $concurrency)) as $wave) {
            $outs = [];
            $results = Process::pool(function (ProcessPool $pool) use ($mediaPath, $secondsAt, $workDir, $prefix, $wave, &$outs): void {
                foreach ($wave as $tMs) {
                    $out = rtrim($workDir, '/')."/{$prefix}_{$tMs}.png";
                    $outs[$tMs] = $out;

                    $pool->as((string) $tMs)->command([
                        $this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-y',
                        '-ss', sprintf('%.3f', $secondsAt($tMs)),
                        '-i', $mediaPath,
                        '-frames:v', '1',
                        '-vf', "scale='min({$this->maxEdge},iw)':'-2'",
                        $out,
                    ]);
                }
            })->start()->wait();

            // Pool keys were registered as (string) $tMs, but PHP array semantics store numeric
            // strings as int keys — so the int $tMs indexes the results directly.
            foreach ($outs as $tMs => $out) {
                if ($results[$tMs]->successful() && is_file($out) && filesize($out) > 0) {
                    $frames[$tMs] = $out;
                }
            }
        }

        return $frames;
    }
}
