<?php

declare(strict_types=1);

namespace App\Actions\Inference;

use App\Inference\VisionClient;

/**
 * Detect objects in an image via the Florence-2 vision sidecar. Florence-2 returns
 * parallel `bboxes` + `labels` arrays; we zip them into one object per detection.
 */
class DetectObjects
{
    public function __construct(private readonly VisionClient $vision) {}

    /**
     * @return list<array{label: string, box: list<float>}> Each detection with its
     *                                                      pixel-space box [x1, y1, x2, y2].
     */
    public function handle(string $image): array
    {
        $result = $this->vision->task($image, '<OD>')['result'];

        if (! is_array($result)) {
            return [];
        }

        $boxes = is_array($result['bboxes'] ?? null) ? array_values($result['bboxes']) : [];
        $labels = is_array($result['labels'] ?? null) ? array_values($result['labels']) : [];

        $objects = [];
        foreach ($labels as $i => $label) {
            $box = $boxes[$i] ?? [];
            $objects[] = [
                'label' => (string) $label,
                'box' => array_map(static fn ($coordinate): float => (float) $coordinate, is_array($box) ? array_values($box) : []),
            ];
        }

        return $objects;
    }
}
