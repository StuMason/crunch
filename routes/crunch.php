<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EmbeddingController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\TextController;
use App\Http\Controllers\Api\TranscriptionController;
use App\Http\Middleware\CrunchAuth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| crunch inference API (root-level, OpenAI-compatible)
|--------------------------------------------------------------------------
| Mounted at the domain root (no /api prefix) so OpenAI SDKs and the existing
| `/crunch` skill work against base_url = https://crunch.stumason.dev.
| Auth: Sanctum personal access token OR the legacy master key (CrunchAuth).
*/
Route::middleware(CrunchAuth::class)->group(function () {
    // Embeddings
    Route::post('/v1/embeddings', [EmbeddingController::class, 'openai']);

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
