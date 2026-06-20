<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EmbeddingController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\TextController;
use App\Http\Controllers\Api\TranscriptionController;
use App\Http\Middleware\CrunchApiKey;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| crunch inference API (root-level, OpenAI-compatible)
|--------------------------------------------------------------------------
| Mounted at the domain root (no /api prefix) so OpenAI SDKs and the existing
| `/crunch` skill work against base_url = https://crunch.stumason.dev.
| Guarded by the interim bearer key; swapped to Sanctum per-token auth later.
*/
Route::middleware(CrunchApiKey::class)->group(function () {
    // Embeddings
    Route::post('/v1/embeddings', [EmbeddingController::class, 'openai']);
    Route::post('/embed', [EmbeddingController::class, 'embed']);

    // Text: rerank + classification
    Route::post('/rerank', [TextController::class, 'rerank']);
    Route::post('/sentiment', [TextController::class, 'sentiment']);
    Route::post('/moderate', [TextController::class, 'moderate']);

    // Image: zero-shot classification + captioning
    Route::post('/classify-image', [ImageController::class, 'classify']);
    Route::post('/caption', [ImageController::class, 'caption']);

    // Audio: async transcription (queue) + job polling
    Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);
    Route::get('/jobs/{job}', [TranscriptionController::class, 'show']);
});
