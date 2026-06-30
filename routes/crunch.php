<?php

declare(strict_types=1);

use App\Http\Controllers\Api\EmbeddingController;
use App\Http\Controllers\Api\ImageController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\OcrBatchController;
use App\Http\Controllers\Api\PackController;
use App\Http\Controllers\Api\TextController;
use App\Http\Controllers\Api\TranscriptionController;
use App\Http\Middleware\CrunchAuth;
use App\Http\Middleware\LimitInflight;
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
    Route::post('/summarize', [TextController::class, 'summarize']);

    // Image: zero-shot classification runs in-process (CLIP), so it scales with workers.
    Route::post('/classify-image', [ImageController::class, 'classify']);

    // Caption/OCR/detect share the single, synchronous Florence-2 vision sidecar — cap
    // in-flight concurrency so a burst gets a clean 429 instead of wedging the pool.
    Route::middleware(LimitInflight::class.':vision')->group(function () {
        Route::post('/caption', [ImageController::class, 'caption']);
        Route::post('/ocr', [ImageController::class, 'ocr']);
        Route::post('/detect', [ImageController::class, 'detect']);
    });

    // Audio: async transcription (queue).
    Route::post('/transcribe', [TranscriptionController::class, 'transcribe']);

    // Batch OCR: many crops in one async job, drained within the vision inflight cap (the
    // submit is cheap — it just persists + queues — so it doesn't need the LimitInflight guard).
    Route::post('/ocr/batch', [OcrBatchController::class, 'store']);

    // Roll pack: upload a take archive, get back the joined crunch.json (async). Submit just
    // persists + queues; the heavy frames/OCR/transcribe pipeline runs on the queue worker.
    Route::post('/pack', [PackController::class, 'store']);

    // Poll any async job (transcribe, ocr-batch) by id.
    Route::get('/jobs/{job}', [JobController::class, 'show']);
});
