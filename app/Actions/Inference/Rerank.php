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
     * @param  list<string>  $texts
     * @return list<array{index: int, score: float, text: string}>
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
                'index' => $index,
                'score' => round($this->sigmoid((float) $raw), 6),
                'text' => $text,
            ];
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $topK !== null ? array_slice($scored, 0, $topK) : $scored;
    }

    private function sigmoid(float $x): float
    {
        return 1 / (1 + exp(-$x));
    }
}
