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
     *
     * **Batching:** inputs are embedded one at a time on CPU (~100–175ms each), so keep
     * a single request to **64 inputs or fewer** (the hard cap; larger requests get a
     * 422). For bulk backfills, chunk client-side and send the chunks in sequence.
     */
    public function openai(Request $request, Embed $embed): JsonResponse
    {
        $validated = $request->validate([
            'input' => ['required', $this->stringOrArrayRule()],
            'input.*' => ['string'],
        ]);

        $texts = $this->normaliseTexts($validated['input']);

        return response()->json([
            'object' => 'list',
            'data' => $this->toEmbeddingData($embed->handle($texts)),
            'model' => config('crunch.models.embed.model'),
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);
    }

    /**
     * Shape vectors into OpenAI embedding objects. Extracted (rather than inlined) so the
     * precise item shape — `embedding` as a float list, `index` as an int — is carried in
     * the return type and reflected accurately in the generated OpenAPI schema.
     *
     * @param  list<list<float>>  $vectors
     * @return list<array{object: string, index: int, embedding: list<float>}>
     */
    private function toEmbeddingData(array $vectors): array
    {
        $data = [];
        foreach ($vectors as $index => $vector) {
            $data[] = [
                'object' => 'embedding',
                'index' => (int) $index,
                'embedding' => $this->floatVector($vector),
            ];
        }

        return $data;
    }

    /**
     * Identity pass over a vector — its only job is to carry the `list<float>` type so the
     * embedding is documented as a number array (Scramble can't trace the loop value alone).
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function floatVector(array $vector): array
    {
        return $vector;
    }

    /**
     * Reject scalars that aren't strings (e.g. a bare number) so input isn't silently
     * coerced. A single string or an array of strings is allowed.
     */
    private function stringOrArrayRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_string($value) && ! is_array($value)) {
                $fail("The {$attribute} field must be a string or an array of strings.");
            }
        };
    }

    /**
     * @return list<string>
     */
    private function normaliseTexts(mixed $input): array
    {
        $texts = array_values(array_map(strval(...), is_array($input) ? $input : [$input]));

        $max = (int) config('crunch.max_batch', 64);
        if (count($texts) > $max) {
            abort(422, "Too many inputs: {$max} max per request (got ".count($texts).'). Chunk the request client-side.');
        }

        return $texts;
    }
}
