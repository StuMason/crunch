<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\InferenceManager;

/**
 * Produce embedding vectors for one or more texts.
 */
class Embed
{
    public function __construct(private readonly InferenceManager $inference) {}

    /**
     * @param  list<string>  $texts
     * @return list<list<float>> One vector per input text (input order preserved).
     */
    public function handle(array $texts): array
    {
        $pipeline = $this->inference->engine('embed');
        $config = $this->inference->config('embed');

        $vectors = [];

        foreach ($texts as $text) {
            $output = $pipeline(
                $text,
                normalize: $config['normalize'] ?? true,
                pooling: $config['pooling'] ?? 'mean',
            );

            $array = is_array($output) ? $output : $output->toArray();
            // feature-extraction returns a [1, dim] batch for a single input
            $vectors[] = $array[0];
        }

        return $vectors;
    }
}
