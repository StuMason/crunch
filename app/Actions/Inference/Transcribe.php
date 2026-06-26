<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\AsrClient;

/**
 * Verbatim speech-to-text. Delegates to the Python ASR sidecar (CrisperWhisper) —
 * see App\Inference\AsrClient — and returns the full raw result: text plus
 * word-level timestamps, fillers and all. Run async (heavy, scales with audio
 * length); see TranscribeJob.
 */
class Transcribe
{
    public function __construct(private readonly AsrClient $asr) {}

    /**
     * @return array{task: string, language: string, duration: float, text: string, words: list<array{word: string, start: float, end: float}>, model: string, infer_secs: float}
     */
    public function handle(string $audioPath): array
    {
        return $this->asr->transcribe($audioPath);
    }
}
