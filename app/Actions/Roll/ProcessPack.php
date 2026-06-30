<?php

declare(strict_types=1);

namespace App\Actions\Roll;

use App\DataTransferObjects\Roll\Pack;
use App\Inference\AsrClient;
use App\Inference\OcrClient;
use App\Jobs\ProcessPackJob;
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
        private readonly CrunchAssembler $assembler,
    ) {}

    /**
     * @return array<string, mixed> the crunch.json structure
     */
    public function handle(string $packDir, string $packId): array
    {
        $pack = $this->reader->read($packDir);

        $ocrWordsByFrame = $this->ocrFrames($pack, rtrim($packDir, '/').'/_frames');
        $words = $this->transcribe($pack);

        return $this->assembler->assemble($pack, $packId, $ocrWordsByFrame, $words);
    }

    /**
     * Extract the sampled screen frames and word-OCR each one (tesseract TSV, confidence-filtered).
     * Returns t_ms => the frame's OCR words (with pixel boxes), dropping frames that read nothing.
     *
     * @return array<int, list<array{text: string, conf: float, box: array<int, int>, line: array<int, int>}>>
     */
    private function ocrFrames(Pack $pack, string $workDir): array
    {
        $frames = $this->extractor->extract($pack, $this->sampler->sample($pack), $workDir);

        $ocrWordsByFrame = [];
        foreach ($frames as $tMs => $path) {
            try {
                $words = $this->ocr->words($path)['words'];
                if ($words !== []) {
                    $ocrWordsByFrame[$tMs] = $words;
                }
            } catch (Throwable) {
                // A frame that won't OCR is dropped from the index, not fatal.
            } finally {
                @unlink($path);
            }
        }

        return $ocrWordsByFrame;
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
