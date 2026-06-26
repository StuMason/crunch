<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\InferenceManager;

/**
 * Rerank candidate texts against a query with a cross-encoder.
 *
 * transformers-php has no rerank pipeline, so we drive the tokenizer + sequence
 * classification model directly: each (query, candidate) pair is scored together,
 * then sorted by relevance (descending).
 */
class Rerank
{
    public function __construct(private readonly InferenceManager $inference) {}

    /**
     * Score each candidate against the query and return them best-first in the Cohere
     * rerank shape (the de-facto standard everyone copies): each result carries its
     * original `index` and a `relevance_score`. The caller maps `index` back to its texts.
     *
     * @param  list<string>  $texts
     * @return list<array{index: int, relevance_score: float}>
     */
    public function handle(string $query, array $texts, ?int $topK = null): array
    {
        $engine = $this->inference->engine('rerank');
        $tokenizer = $engine['tokenizer'];
        $model = $engine['model'];

        $scored = [];

        foreach ($texts as $index => $text) {
            $inputs = $tokenizer->tokenize($query, textPair: $text, padding: true, truncation: true);
            $logits = $model($inputs)->logits->toArray();
            $raw = is_array($logits[0]) ? $logits[0][0] : $logits[0];

            $scored[] = [
                'index' => (int) $index,
                'relevance_score' => round($this->sigmoid((float) $raw), 6),
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['relevance_score'] <=> $a['relevance_score']);

        return $topK !== null ? array_slice($scored, 0, $topK) : $scored;
    }

    private function sigmoid(float $x): float
    {
        return 1 / (1 + exp(-$x));
    }
}
