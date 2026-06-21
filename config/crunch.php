<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Interim API key
    |--------------------------------------------------------------------------
    | A single bearer key guards the API while the walking skeleton is deployed.
    | Replaced by Sanctum per-token auth in the management layer (see roadmap).
    */
    'api_key' => env('CRUNCH_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Model cache
    |--------------------------------------------------------------------------
    | Where transformers-php downloads ONNX models. Backed by a persistent
    | volume in production so models survive redeploys.
    */
    'cache_dir' => env('CRUNCH_MODEL_CACHE', storage_path('app/models')),

    /*
    |--------------------------------------------------------------------------
    | Max batch size
    |--------------------------------------------------------------------------
    | Inference runs one input at a time on CPU (~100-175ms each), so a single
    | request with too many inputs walks straight into the Octane worker timeout
    | and 500s. Cap the count and return a clean 422 instead. Bulk backfills
    | should chunk client-side and stay at or under this.
    */
    'max_batch' => (int) env('CRUNCH_MAX_BATCH', 64),

    /*
    |--------------------------------------------------------------------------
    | Pre-warm
    |--------------------------------------------------------------------------
    | Capabilities to load into memory when an Octane worker boots, so the first
    | request is already warm. Each worker holds its own copy — keep this to hot,
    | lightweight capabilities; heavy ones (Whisper) lazy-load on first use.
    */
    'warm' => array_filter(explode(',', (string) env('CRUNCH_WARM', 'embed'))),

    /*
    |--------------------------------------------------------------------------
    | Capability → model map (the LOCKED v2 set, verified on arm64 2026-06-20)
    |--------------------------------------------------------------------------
    | Every model is a one-line swap. `quantized:false` + an explicit
    | `model_filename` selects a specific single-file ONNX variant
    | (onnx-community repos don't follow transformers-php's default naming).
    | `kind` drives how InferenceManager builds the engine.
    */
    'models' => [
        'embed' => [
            'kind' => 'pipeline',
            'task' => 'feature-extraction',
            'model' => 'onnx-community/Qwen3-Embedding-0.6B-ONNX',
            'quantized' => false,
            'model_filename' => 'model_quantized',
            'pooling' => 'last_token', // Qwen3-Embedding is a causal embedder → last-token, not mean
            'normalize' => true,
            'dimensions' => 1024,
        ],

        // --- wired in subsequent slices (verified working in the spike) ---
        'rerank' => [
            'kind' => 'cross-encoder',
            'model' => 'Xenova/ms-marco-MiniLM-L-6-v2',
        ],
        'sentiment' => [
            'kind' => 'pipeline',
            'task' => 'text-classification',
            'model' => 'SamLowe/roberta-base-go_emotions-onnx', // the -onnx repo has the ONNX files
            'top_k' => null,
        ],
        'moderate' => [
            'kind' => 'pipeline',
            'task' => 'text-classification',
            'model' => 'KoalaAI/Text-Moderation',
            'quantized' => false,
            'top_k' => null,
        ],
        'classify-image' => [
            'kind' => 'pipeline',
            'task' => 'zero-shot-image-classification',
            'model' => 'Xenova/clip-vit-base-patch32',
        ],
        'caption' => [
            // capped at vit-gpt2 in transformers-php (BLIP/Florence-2 unsupported)
            'kind' => 'pipeline',
            'task' => 'image-to-text',
            'model' => 'Xenova/vit-gpt2-image-captioning',
        ],
        'transcribe' => [
            'kind' => 'pipeline',
            'task' => 'automatic-speech-recognition',
            'model' => 'onnx-community/distil-small.en',
            'quantized' => false, // spike-proven fp32; runs in the queue worker, lazy-loaded
            'async' => true,
        ],
    ],
];
