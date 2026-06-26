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
    | ASR sidecar
    |--------------------------------------------------------------------------
    | Verbatim speech-to-text (CrisperWhisper) runs in a small Python service —
    | the one capability the PHP/ONNX core can't do. Reached over the internal
    | compose network; never exposed publicly. `timeout` is generous because CPU
    | transcription of long audio is minutes, not milliseconds (it's an async job).
    */
    'asr' => [
        'url' => env('CRUNCH_ASR_URL', 'http://asr:9000'),
        'timeout' => (int) env('CRUNCH_ASR_TIMEOUT', 600),
    ],

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
            // Bumped L-6 -> L-12 (same ms-marco BERT family): 2x layers, stronger relevance at
            // effectively no latency cost (~22ms warm, same as L-6). NOTE: modern rerankers
            // (bge/gte) are XLM-RoBERTa, which transformers-php's sequence-classification path
            // does NOT support, so the BERT-family ms-marco models are the in-core ceiling here.
            'model' => 'Xenova/ms-marco-MiniLM-L-12-v2',
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
            // Bumped from clip-vit-base-patch32 (~150M): the large/14 variant has much stronger
            // zero-shot accuracy (B/32 mis-ranked an obvious dog photo below "landscape"). Same
            // pipeline + Xenova ONNX, just bigger (~1.7GB, ~500-700ms warm) — fits the headroom.
            'model' => 'Xenova/clip-vit-large-patch14',
        ],
        'caption' => [
            // capped at vit-gpt2 in transformers-php (BLIP/Florence-2 unsupported)
            'kind' => 'pipeline',
            'task' => 'image-to-text',
            'model' => 'Xenova/vit-gpt2-image-captioning',
        ],
        'transcribe' => [
            // Verbatim STT runs in the Python ASR sidecar (asr-sidecar/), NOT the
            // PHP/ONNX pipeline: CrisperWhisper keeps fillers + word timestamps, which
            // vanilla ONNX Whisper deletes by design. The PHP side just calls it over
            // HTTP — see the `asr` block below for the endpoint.
            'kind' => 'sidecar',
            'model' => env('CRUNCH_ASR_MODEL', 'large-v3-turbo'),
            'async' => true,
        ],
    ],
];
