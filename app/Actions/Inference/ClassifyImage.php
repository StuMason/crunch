<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\InferenceManager;

/**
 * Zero-shot image classification (CLIP) — score an image against caller-supplied labels.
 */
class ClassifyImage
{
    public function __construct(private readonly InferenceManager $inference) {}

    /**
     * @param  list<string>  $labels
     * @return list<array{label: string, score: float}> Labels with scores (descending).
     */
    public function handle(string $image, array $labels): array
    {
        $pipeline = $this->inference->engine('classify-image');

        $output = $pipeline($image, $labels);

        $results = array_map(fn (array $row): array => [
            'label' => $row['label'],
            'score' => round((float) $row['score'], 6),
        ], (array) $output);

        usort($results, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values($results);
    }
}
