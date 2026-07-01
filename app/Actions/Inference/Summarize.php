<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\InferenceManager;

/**
 * Abstractive summarisation via transformers-php (distilbart-cnn ONNX, CPU-only). Deliberately a
 * small model — no GPU here, and a generative LLM would crawl — so treat the output as a rough
 * gist, not polished prose. Same warm-engine pattern as the other text capabilities.
 */
class Summarize
{
    public function __construct(private readonly InferenceManager $inference) {}

    public function handle(string $text): string
    {
        $output = ($this->inference->engine('summarize'))($text);

        return $this->extract($output);
    }

    /**
     * The summarization pipeline returns `[{summary_text: "…"}]` (or a single such row); pull the
     * text out of whichever shape we got.
     */
    private function extract(mixed $output): string
    {
        $row = is_array($output) ? ($output[0] ?? $output) : $output;

        if (is_array($row)) {
            return trim((string) ($row['summary_text'] ?? $row['generated_text'] ?? ''));
        }

        return trim((string) $row);
    }
}
