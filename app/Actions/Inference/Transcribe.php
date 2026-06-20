<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\InferenceManager;

/**
 * Speech-to-text (Whisper). Run async (heavy, scales with audio length) — see TranscribeJob.
 */
class Transcribe
{
    public function __construct(private readonly InferenceManager $inference) {}

    public function handle(string $audioPath): string
    {
        $pipeline = $this->inference->engine('transcribe');

        $output = $pipeline($audioPath);

        return trim((string) (($output['text'] ?? '')));
    }
}
