<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\InferenceManager;

/**
 * Text classification — backs both sentiment (go_emotions) and moderation
 * (KoalaAI multi-category). Same pipeline, different model per capability.
 */
class ClassifyText
{
    public function __construct(private readonly InferenceManager $inference) {}

    /**
     * @return list<array{label: string, score: float}> Labels with scores (descending).
     */
    public function handle(string $capability, string $text): array
    {
        $pipeline = $this->inference->engine($capability);
        $topK = $this->inference->config($capability)['top_k'] ?? null;

        $output = $pipeline($text, topK: $topK);

        return $this->normalise($output);
    }

    /**
     * Normalise pipeline output (single {label,score} or a list of them) to a sorted list.
     *
     * @return list<array{label: string, score: float}>
     */
    private function normalise(mixed $output): array
    {
        $rows = array_key_exists('label', (array) $output) ? [$output] : (array) $output;

        $results = array_map(fn (array $row): array => [
            'label' => $row['label'],
            'score' => round((float) $row['score'], 6),
        ], $rows);

        usort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $results;
    }
}
