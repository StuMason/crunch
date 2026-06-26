<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\VisionClient;

/**
 * Read the text out of an image (OCR) via the Florence-2 vision sidecar.
 */
class Ocr
{
    public function __construct(private readonly VisionClient $vision) {}

    public function handle(string $image): string
    {
        return trim((string) $this->vision->task($image, '<OCR>')['result']);
    }
}
