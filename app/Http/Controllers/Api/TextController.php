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
     * Rerank results by relevance
     *
     * Stage two of good search. Embeddings get you a rough shortlist fast; rerank then
     * reads your `query` and each candidate *together* and scores how well each one
     * actually answers it — far more accurate. Typical flow: embed → fetch top ~20 →
     * rerank → keep the best 5.
     *
     * **Send:** `query` (the search), `texts` (the candidates to score), optional `top_k`
     * (only return the best N).
     * **Get back:** the candidates sorted best-first, each with its original `index` and a `score`.
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
     * Detect emotion / sentiment
     *
     * Reads a piece of text and scores the emotions in it across 28 categories
     * (admiration, anger, joy, sadness, …) — handy for tagging feedback, reviews,
     * support messages or comments.
     *
     * **Send:** `inputs` — the text.
     * **Get back:** the emotion labels with scores (0–1), strongest first.
     */
    public function sentiment(Request $request, ClassifyText $classify): JsonResponse
    {
        return $this->classify($request, $classify, 'sentiment');
    }

    /**
     * Moderate content
     *
     * Flags potentially harmful text across categories (hate, harassment, sexual,
     * violence, self-harm, …) so you can block, hide, or queue it for review before
     * it hits your app.
     *
     * **Send:** `inputs` — the text to check.
     * **Get back:** category labels with scores (0–1); act on whatever crosses your
     * own threshold.
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
