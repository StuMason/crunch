<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\VisionClient;

/**
 * Image captioning. Runs in the Python vision sidecar (Florence-2-base) because the
 * ONNX/transformers-php image-to-text pipeline caps at vit-gpt2 — see config/crunch.php.
 */
class Caption
{
    public function __construct(private readonly VisionClient $vision) {}

    /**
     * @param  string  $detail  Caption verbosity: `normal`, `detailed`, or `more`.
     */
    public function handle(string $image, string $detail = 'normal'): string
    {
        return trim($this->vision->caption($image, $detail)['caption']);
    }
}
