<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inference\ClassifyText;
use App\Actions\Inference\Rerank;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TextController extends Controller
{
    /**
     * Rerank candidate texts by relevance to a query: POST /rerank.
     */
    public function rerank(Request $request, Rerank $rerank): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string'],
            'texts' => ['required', 'array', 'min:1'],
            'texts.*' => ['string'],
            'top_k' => ['sometimes', 'integer', 'min:1'],
        ]);

        return response()->json([
            'model' => config('crunch.models.rerank.model'),
            'results' => $rerank->handle(
                $validated['query'],
                array_values($validated['texts']),
                $validated['top_k'] ?? null,
            ),
        ]);
    }

    /**
     * Emotion/sentiment classification (28 go_emotions labels): POST /sentiment.
     */
    public function sentiment(Request $request, ClassifyText $classify): JsonResponse
    {
        return $this->classify($request, $classify, 'sentiment');
    }

    /**
     * Content moderation (multi-category): POST /moderate.
     */
    public function moderate(Request $request, ClassifyText $classify): JsonResponse
    {
        return $this->classify($request, $classify, 'moderate');
    }

    private function classify(Request $request, ClassifyText $classify, string $capability): JsonResponse
    {
        $validated = $request->validate([
            'inputs' => ['required', 'string'],
        ]);

        return response()->json([
            'model' => config("crunch.models.$capability.model"),
            'results' => $classify->handle($capability, $validated['inputs']),
        ]);
    }
}
