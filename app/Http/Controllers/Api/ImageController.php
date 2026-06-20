<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inference\Caption;
use App\Actions\Inference\ClassifyImage;
use App\Http\Controllers\Controller;
use App\Support\MediaResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImageController extends Controller
{
    /**
     * Zero-shot image classification: POST /classify-image.
     * Image via `url`, base64 `image`, or uploaded `image`; plus `labels[]`.
     */
    public function classify(Request $request, ClassifyImage $classify): JsonResponse
    {
        $validated = $request->validate([
            'labels' => ['required', 'array', 'min:1'],
            'labels.*' => ['string'],
        ]);

        [$image, $isTemp] = MediaResolver::resolve($request, 'image');

        try {
            $results = $classify->handle($image, array_values($validated['labels']));
        } finally {
            if ($isTemp) {
                @unlink($image);
            }
        }

        return response()->json([
            'model' => config('crunch.models.classify-image.model'),
            'results' => $results,
        ]);
    }

    /**
     * Image captioning: POST /caption. Image via `url`, base64 `image`, or upload.
     */
    public function caption(Request $request, Caption $caption): JsonResponse
    {
        [$image, $isTemp] = MediaResolver::resolve($request, 'image');

        try {
            $text = $caption->handle($image);
        } finally {
            if ($isTemp) {
                @unlink($image);
            }
        }

        return response()->json([
            'model' => config('crunch.models.caption.model'),
            'caption' => $text,
        ]);
    }
}
