<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\OcrClient;
use App\Inference\VisionClient;

/**
 * Read the text out of an image (OCR).
 *
 * Routes by engine: `tesseract` (default) and `paddle` go to the dedicated OCR sidecar
 * (purpose-built, clean on dense UI text); `florence` falls back to the Florence-2 vision
 * sidecar (the original path — generative, weaker on small glyphs, but kept for parity).
 */
class Ocr
{
    public function __construct(
        private readonly VisionClient $vision,
        private readonly OcrClient $ocr,
    ) {}

    public function handle(string $image, string $engine = 'tesseract', ?int $psm = null): string
    {
        if ($engine === 'florence') {
            return trim((string) $this->vision->task($image, '<OCR>')['result']);
        }

        return $this->ocr->ocr($image, $engine, $psm);
    }

    /**
     * Human-readable model label for an engine — reported back on OCR responses.
     */
    public static function modelLabel(string $engine): string
    {
        return match ($engine) {
            'paddle' => 'PaddleOCR PP-OCRv6',
            'florence' => (string) config('crunch.models.ocr.model'),
            default => 'tesseract',
        };
    }
}
