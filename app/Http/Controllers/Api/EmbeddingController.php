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
     * OpenAI-compatible embeddings endpoint: POST /v1/embeddings.
     * Accepts `input` as a string or array of strings.
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
     * Native embeddings endpoint: POST /embed. Accepts `inputs` (string|array),
     * returns a bare list of vectors.
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
