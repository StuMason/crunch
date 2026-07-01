<?php

declare(strict_types=1);

namespace App\Actions\Roll;

use App\Actions\Inference\ClassifyImage;
use App\DataTransferObjects\Roll\Pack;
use App\Inference\AsrClient;
use App\Inference\OcrClient;
use App\Jobs\ProcessPackJob;
use App\Support\Roll\AudioProsody;
use App\Support\Roll\CrunchAssembler;
use App\Support\Roll\FrameExtractor;
use App\Support\Roll\FrameSampler;
use App\Support\Roll\PackReader;
use Throwable;

/**
 * Turns a roll pack directory into the joined `crunch.json` index — the core of the "Cruncher".
 *
 * Read the pack → pick frames → OCR them (screen) → transcribe the mic (audio) → join it all
 * on the shared clock. Reusable: callable straight from a test or synchronously, with the
 * async {@see ProcessPackJob} just wrapping it. Per-frame OCR and the transcript are
 * each best-effort — a frame that won't OCR or a missing mic degrades the output, never sinks
 * the whole job.
 */
class ProcessPack
{
    public function __construct(
        private readonly PackReader $reader,
        private readonly FrameSampler $sampler,
        private readonly FrameExtractor $extractor,
        private readonly OcrClient $ocr,
        private readonly AsrClient $asr,
        private readonly AudioProsody $audioProsody,
        private readonly CrunchAssembler $assembler,
        private readonly ClassifyImage $classify,
    ) {}

    /**
     * @return array<string, mixed> the crunch.json structure
     */
    public function handle(string $packDir, string $packId): array
    {
        $pack = $this->reader->read($packDir);
        $workDir = rtrim($packDir, '/').'/_frames';

        [$ocrLinesByFrame, $frameHeight] = $this->ocrFrames($pack, $workDir);
        $words = $this->transcribe($pack);
        $sysWords = $this->transcribeSysAudio($pack);
        $prosodyMoments = $this->prosody($pack);
        $cameraSamples = $this->cameraPresence($pack, $workDir);

        return $this->assembler->assemble(
            $pack, $packId, $ocrLinesByFrame, $words,
            frameHeight: $frameHeight,
            prosodyMoments: $prosodyMoments,
            sysWords: $sysWords,
            cameraSamples: $cameraSamples,
        );
    }

    /**
     * On-camera presence samples: sample the camera track at a coarse cadence, extract each frame,
     * and score it with zero-shot CLIP (in-process) against the present/absent labels. A frame is
     * on-camera when the "present" label wins with score >= the configured threshold. Best-effort —
     * no camera, or ffmpeg/CLIP failures, just yield no samples (and so no camera track).
     *
     * @return list<array{t_ms: int, present: bool, score: float}> sorted by t_ms
     */
    private function cameraPresence(Pack $pack, string $workDir): array
    {
        if (! $pack->manifest->hasCamera()) {
            return [];
        }

        /** @var array{cadence_ms: int, max_frames: int, present_threshold: float, present_label: string, absent_label: string} $cfg */
        $cfg = config('crunch.camera');
        $times = $this->sampler->cadence((int) round($pack->manifest->durationMs), $cfg['cadence_ms'], $cfg['max_frames']);
        $frames = $this->extractor->extractCamera($pack, $times, $workDir);

        $samples = [];
        foreach ($frames as $tMs => $path) {
            try {
                $scores = $this->classify->handle($path, [$cfg['present_label'], $cfg['absent_label']]);
                $present = $scores[0]['label'] === $cfg['present_label'] ? $scores[0]['score'] : ($scores[1]['score'] ?? 0.0);
                $samples[] = ['t_ms' => (int) $tMs, 'present' => $present >= $cfg['present_threshold'], 'score' => round((float) $present, 4)];
            } catch (Throwable) {
                // A frame that won't classify is skipped, not fatal.
            } finally {
                @unlink($path);
            }
        }

        usort($samples, fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);

        return $samples;
    }

    /**
     * Transcribe the system-audio track (computer output — a video/call the user played) if present,
     * placing each word on the SHARED clock via the sysaudio sync offset. Kept as a separate track
     * from the mic (the presenter's voice). Best-effort — missing/unreadable sysaudio yields nothing.
     *
     * @return list<array{word: string, t_ms: int}>
     */
    private function transcribeSysAudio(Pack $pack): array
    {
        if (! $pack->manifest->hasSysAudio()) {
            return [];
        }

        try {
            $result = $this->asr->transcribe($pack->absolutePath((string) $pack->manifest->sysAudioFile));
        } catch (Throwable) {
            return [];
        }

        $words = [];
        foreach ($result['words'] as $word) {
            $text = trim($word['word']);
            if ($text === '') {
                continue;
            }
            $words[] = [
                'word' => $text,
                't_ms' => $pack->manifest->sysAudioTMsForSeconds($word['start']),
            ];
        }

        return $words;
    }

    /**
     * Vocal-emphasis + pause moments from the mic track (best-effort — a missing or unreadable mic
     * just yields no prosody moments).
     *
     * @return list<array{t_ms: int, kind: string, label: string, score: float, source: string}>
     */
    private function prosody(Pack $pack): array
    {
        if (! $pack->manifest->hasMic()) {
            return [];
        }

        try {
            return $this->audioProsody->analyze(
                $pack->absolutePath((string) $pack->manifest->micFile),
                (int) round($pack->manifest->micSyncOffsetMs),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Line-OCR the screen frames (tesseract, line-level). roll's pre-rendered keyframes (lossless
     * PNGs it captured at the salient moments) are used directly where present, and the rest of the
     * sampled timeline is filled by extracting frames from `screen.mp4` — so persistent on-screen
     * text between interactions is still captured. Returns a tuple of [t_ms => the frame's OCR lines
     * (text + pixel box), frame pixel height] — the height lets the assembler discard the top-of-frame
     * OS chrome. Frames that read nothing are dropped.
     *
     * @return array{0: array<int, list<array{text: string, conf: float, box: array<int, int>}>>, 1: int}
     */
    private function ocrFrames(Pack $pack, string $workDir): array
    {
        $extractTimes = $this->timesNeedingExtraction($this->sampler->sample($pack), array_keys($pack->keyframes));
        $extracted = $this->extractor->extract($pack, $extractTimes, $workDir);

        // Keyframe paths win on any t_ms collision (lossless PNG beats a re-extracted frame).
        $frames = $pack->keyframes + $extracted;
        ksort($frames);

        $workPrefix = rtrim($workDir, '/').'/';

        $ocrLinesByFrame = [];
        $frameHeight = 0;
        foreach ($frames as $tMs => $path) {
            try {
                $result = $this->ocr->lines($path);
                $frameHeight = $frameHeight ?: $result['image_height'];
                if ($result['lines'] !== []) {
                    $ocrLinesByFrame[$tMs] = $result['lines'];
                }
            } catch (Throwable) {
                // A frame that won't OCR is dropped from the index, not fatal.
            } finally {
                // Only extracted temp frames live under $workDir; roll's keyframes are pack input — never delete them.
                if (str_starts_with($path, $workPrefix)) {
                    @unlink($path);
                }
            }
        }

        return [$ocrLinesByFrame, $frameHeight];
    }

    /**
     * Of the frame timestamps the sampler chose, the ones that still need extracting from
     * `screen.mp4` — i.e. those NOT already covered by a pre-rendered keyframe (within
     * $mergeWithinMs, so a keyframe a few ms off a sampled tick isn't OCR'd twice). Pure +
     * deterministic so the keyframe/extraction split is unit-testable without ffmpeg.
     *
     * @param  list<int>  $sampleTimes
     * @param  list<int>  $keyframeTimes
     * @return list<int>
     */
    public function timesNeedingExtraction(array $sampleTimes, array $keyframeTimes, int $mergeWithinMs = 200): array
    {
        return array_values(array_filter($sampleTimes, function (int $t) use ($keyframeTimes, $mergeWithinMs): bool {
            foreach ($keyframeTimes as $k) {
                if (abs($k - $t) <= $mergeWithinMs) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Transcribe the mic track (if present) and place each word back on the SHARED clock —
     * the sidecar's word times are seconds into `mic.m4a`, which starts `micSyncOffsetMs`
     * after t0, so they must be shifted to line up with the input events.
     *
     * @return list<array{word: string, t_ms: int}>
     */
    private function transcribe(Pack $pack): array
    {
        if (! $pack->manifest->hasMic()) {
            return [];
        }

        try {
            $result = $this->asr->transcribe($pack->absolutePath((string) $pack->manifest->micFile));
        } catch (Throwable) {
            return [];
        }

        $words = [];
        foreach ($result['words'] as $word) {
            $text = trim($word['word']);
            if ($text === '') {
                continue;
            }
            $words[] = [
                'word' => $text,
                't_ms' => $pack->manifest->micTMsForSeconds($word['start']),
            ];
        }

        return $words;
    }
}
