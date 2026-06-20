<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\InferenceManager;

/**
 * Image captioning (image-to-text). Capped at vit-gpt2 in transformers-php — see
 * config/crunch.php; a better captioner is a planned sidecar.
 */
class Caption
{
    public function __construct(private readonly InferenceManager $inference) {}

    public function handle(string $image): string
    {
        $pipeline = $this->inference->engine('caption');

        $output = $pipeline($image);

        return trim((string) (($output[0]['generated_text'] ?? '')));
    }
}
