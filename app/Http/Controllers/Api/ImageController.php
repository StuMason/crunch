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
     * Classify an image (you supply the labels)
     *
     * Multiple-choice for images: you give a list of `labels`, and it scores how well
     * the picture matches each one. It can only choose from *your* labels (great for
     * "is this safe/NSFW?", product categories, "does this contain a logo?", etc.).
     *
     * **Send the image one of three ways:**
     * - `application/json` with `"url"`: a public image URL crunch fetches — `{"url": "https://…/cat.jpg", "labels": ["a cat","a dog"]}`
     * - `application/json` with `"image"`: the image as a base64 string — `{"image": "<base64>", "labels": [...]}`
     * - `multipart/form-data`: upload the file as the `image` field — `curl -F image=@cat.jpg -F 'labels[]=a cat'`
     *
     * **Get back:** each label with a score (0–1), highest first.
     */
    public function classify(Request $request, ClassifyImage $classify): JsonResponse
    {
        $validated = $request->validate([
            'labels' => ['required', 'array', 'min:1'],
            'labels.*' => ['string'],
            'url' => ['sometimes', 'string'],   // public image URL
            'image' => ['sometimes'],           // base64 string or uploaded file
        ]);

        // libvips reads a local file (it can't fetch URLs), so download/persist first.
        [$image, $isTemp] = MediaResolver::resolve($request, 'image', mustBeLocal: true);

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
     * Caption an image (it writes the description)
     *
     * Open-ended: crunch looks at the picture and writes a sentence describing it — you
     * don't supply any options. Runs Florence-2-base in the vision sidecar, so captions
     * are detailed and accurate (it'll often name the subject, setting and action). CPU
     * inference takes a few seconds.
     *
     * **Send the image one of three ways** (same as classify):
     * - `application/json` with `"url"`: `{"url": "https://…/cat.jpg"}`
     * - `application/json` with `"image"`: base64 string — `{"image": "<base64>"}`
     * - `multipart/form-data`: `curl -F image=@cat.jpg`
     *
     * **Get back:** `{ "caption": "A grey cat curled up asleep on a knitted blanket." }`.
     */
    public function caption(Request $request, Caption $caption): JsonResponse
    {
        $request->validate([
            'url' => ['sometimes', 'string'],   // public image URL
            'image' => ['sometimes'],           // base64 string or uploaded file
        ]);

        // libvips reads a local file (it can't fetch URLs), so download/persist first.
        [$image, $isTemp] = MediaResolver::resolve($request, 'image', mustBeLocal: true);

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
