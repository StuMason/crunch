<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EmbeddingController;
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
    Route::post('/v1/embeddings', [EmbeddingController::class, 'openai']);
    Route::post('/embed', [EmbeddingController::class, 'embed']);
});
