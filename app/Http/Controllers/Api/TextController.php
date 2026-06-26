<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inference\ClassifyText;
use App\Actions\Inference\Rerank;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     * **Get back:** the [Cohere rerank shape](https://docs.cohere.com/reference/rerank) —
     * `results` sorted best-first, each with its original `index` and a `relevance_score`.
     * Map each `index` back to your `texts` array.
     */
    public function rerank(Request $request, Rerank $rerank): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string'],
            'texts' => ['required', 'array', 'min:1', 'max:'.(int) config('crunch.max_batch', 64)],
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
     * **Get back:** the [OpenAI moderations shape](https://platform.openai.com/docs/api-reference/moderations) —
     * a `results` array whose entry has `flagged` (true when the text isn't classed as
     * safe), `categories` (per-category booleans) and `category_scores` (per-category
     * 0–1 scores). The category *names* are the model's own vocabulary (KoalaAI:
     * `H`, `S`, `V`, `HR`, `SH`, …), not OpenAI's, so map them to your own rules.
     */
    public function moderate(Request $request, ClassifyText $classify): JsonResponse
    {
        $validated = $request->validate([
            'inputs' => ['required', 'string'],
        ]);

        $scores = $classify->handle('moderate', $validated['inputs']);

        return response()->json([
            'id' => 'modr-'.Str::lower((string) Str::ulid()),
            'model' => config('crunch.models.moderate.model'),
            'results' => [$this->moderationResult($scores)],
        ]);
    }

    /**
     * Fold the model's per-label scores into one OpenAI-style moderation result. KoalaAI
     * labels the safe class `OK`, so the text is flagged whenever the top label is anything
     * else. `categories` booleans threshold each score at 0.5.
     *
     * @param  list<array{label: string, score: float}>  $scores
     * @return array{flagged: bool, categories: array<string, bool>, category_scores: array<string, float>}
     */
    private function moderationResult(array $scores): array
    {
        $top = $scores[0]['label'] ?? 'OK';

        return [
            'flagged' => $top !== 'OK',
            'categories' => $this->categoryFlags($scores),
            'category_scores' => $this->categoryScores($scores),
        ];
    }

    /**
     * Per-category booleans (score thresholded at 0.5). Split out so the open-map type
     * lands in the OpenAPI schema rather than degrading to a scalar.
     *
     * @param  list<array{label: string, score: float}>  $scores
     * @return array<string, bool>
     */
    private function categoryFlags(array $scores): array
    {
        $map = [];
        foreach ($scores as $row) {
            $map[$row['label']] = $row['score'] >= 0.5;
        }

        return $map;
    }

    /**
     * Per-category raw 0–1 scores.
     *
     * @param  list<array{label: string, score: float}>  $scores
     * @return array<string, float>
     */
    private function categoryScores(array $scores): array
    {
        $map = [];
        foreach ($scores as $row) {
            $map[$row['label']] = $row['score'];
        }

        return $map;
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
