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

        $shouldNormalize = $config['normalize'] ?? true;
        $vectors = [];

        foreach ($texts as $text) {
            $output = $pipeline(
                $text,
                normalize: $shouldNormalize,
                pooling: $config['pooling'] ?? 'mean',
            );

            $array = is_array($output) ? $output : $output->toArray();
            // One text per call → the result is a single vector, but pooling leaves
            // size-1 leading dims ([1,dim] for mean, [1,1,dim] for last_token), so
            // flatten to a flat float list regardless of wrapping.
            $vector = $this->flatten($array);

            $vectors[] = $shouldNormalize ? $this->l2Normalize($vector) : $vector;
        }

        return $vectors;
    }

    /**
     * Flatten an arbitrarily-nested numeric array into a flat list of floats.
     *
     * @param  array<array-key, mixed>  $nested
     * @return list<float>
     */
    private function flatten(array $nested): array
    {
        $flat = [];

        array_walk_recursive($nested, function ($value) use (&$flat): void {
            $flat[] = (float) $value;
        });

        return $flat;
    }

    /**
     * Guarantee unit-length vectors regardless of pipeline behaviour.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function l2Normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector)));

        if ($norm <= 0.0) {
            return $vector;
        }

        return array_map(fn (float $v): float => $v / $norm, $vector);
    }
}
