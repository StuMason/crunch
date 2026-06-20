<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inference\Embed;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmbeddingController extends Controller
{
    /**
     * Embeddings (OpenAI-compatible)
     *
     * Turns text into a **vector** — a list of 1024 numbers that captures the text's
     * *meaning*. Texts that mean similar things get similar vectors, so you can power
     * semantic search, "find related items", clustering, and RAG by comparing vectors
     * (cosine similarity). Vectors come back already unit-normalized.
     *
     * **Send:** `input` — one string, or an array of strings to embed in a batch.
     * **Get back:** the OpenAI shape — `data[].embedding`.
     *
     * This is a drop-in for OpenAI's embeddings endpoint: point any OpenAI SDK at
     * `base_url = https://crunch.stumason.dev` and it just works.
     */
    public function openai(Request $request, Embed $embed): JsonResponse
    {
        $validated = $request->validate([
            'input' => ['required'],
            'input.*' => ['string'],
        ]);

        $texts = $this->normaliseTexts($validated['input']);
        $vectors = $embed->handle($texts);

        $data = [];
        foreach ($vectors as $index => $vector) {
            $data[] = [
                'object' => 'embedding',
                'index' => $index,
                'embedding' => $vector,
            ];
        }

        return response()->json([
            'object' => 'list',
            'data' => $data,
            'model' => config('crunch.models.embed.model'),
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);
    }

    /**
     * Embeddings (simple)
     *
     * The same as `/v1/embeddings` but with a plainer shape — handy if you're not
     * using an OpenAI SDK.
     *
     * **Send:** `inputs` — one string, or an array of strings.
     * **Get back:** a bare list of vectors, one per input, in the same order.
     */
    public function embed(Request $request, Embed $embed): JsonResponse
    {
        $validated = $request->validate([
            'inputs' => ['required'],
            'inputs.*' => ['string'],
        ]);

        $texts = $this->normaliseTexts($validated['inputs']);

        return response()->json($embed->handle($texts));
    }

    /**
     * @return list<string>
     */
    private function normaliseTexts(mixed $input): array
    {
        return array_values(array_map(strval(...), is_array($input) ? $input : [$input]));
    }
}
