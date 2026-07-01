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
     * Line-level OCR (tesseract): each reading-order line with its pixel box + mean confidence,
     * plus the joined text and the image height. This is the structured read the roll pack pipeline
     * uses to resolve a click to the text under it — surfaced here so it's reusable standalone.
     *
     * @return array{lines: list<array{text: string, conf: float, box: array<int, int>}>, text: string, image_height: int}
     */
    public function lines(string $image, ?int $psm = null, ?int $minConf = null): array
    {
        return $this->ocr->lines($image, $psm, $minConf);
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
