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
    | Vision sidecar
    |--------------------------------------------------------------------------
    | Image captioning (Florence-2-base) runs in a small Python service — the ONNX
    | core caps image-to-text at vit-gpt2. Reached over the internal compose network;
    | never exposed publicly. Captioning on CPU is seconds, not milliseconds, but it's
    | a single synchronous call, so the timeout is short relative to the ASR one.
    |
    | `timeout` is kept under Cloudflare's ~100s edge cutoff so a genuinely stuck
    | call surfaces a clean error instead of a CF 524. `max_inflight` caps how many
    | vision requests may hit the single sidecar at once — excess requests get a fast
    | 429 (see App\Http\Middleware\LimitInflight) rather than queueing into timeouts.
    */
    'vision' => [
        'url' => env('CRUNCH_VISION_URL', 'http://vision:9000'),
        'timeout' => (int) env('CRUNCH_VISION_TIMEOUT', 90),
        'max_inflight' => (int) env('CRUNCH_VISION_MAX_INFLIGHT', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | OCR sidecar (ocr-sidecar/)
    |--------------------------------------------------------------------------
    | Dedicated document/UI-text OCR — Florence-2 (the vision sidecar) hallucinates on
    | dense UI glyphs, which is the `roll` workload. `tesseract` is the fast, tiny default;
    | `paddle` (PaddleOCR, lazy-loaded) is the heavier high-accuracy opt-in. `florence`
    | still routes back to the vision sidecar. Engine is chosen per request via `engine=`.
    */
    'ocr' => [
        'url' => env('CRUNCH_OCR_URL', 'http://ocr:9000'),
        'timeout' => (int) env('CRUNCH_OCR_TIMEOUT', 60),
        'default_engine' => env('CRUNCH_OCR_DEFAULT_ENGINE', 'tesseract'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Roll pack — camera (on-camera presence) track
    |--------------------------------------------------------------------------
    | The pack pipeline samples the camera track at `cadence_ms` (presence changes slowly,
    | so coarse is fine) up to `max_frames`, and scores each frame with zero-shot CLIP
    | (in-process, cheap) against the present/absent labels. A frame counts as on-camera when
    | the "present" label wins with score >= `present_threshold`. Contiguous on-camera frames
    | become `camera` spans, and each span's start is an `on_camera` moment.
    */
    'camera' => [
        'cadence_ms' => (int) env('CRUNCH_CAMERA_CADENCE_MS', 2000),
        'max_frames' => (int) env('CRUNCH_CAMERA_MAX_FRAMES', 180),
        'present_threshold' => (float) env('CRUNCH_CAMERA_PRESENT_THRESHOLD', 0.55),
        'present_label' => env('CRUNCH_CAMERA_PRESENT_LABEL', 'a person on camera, a face looking at the screen'),
        'absent_label' => env('CRUNCH_CAMERA_ABSENT_LABEL', 'an empty chair or background, no person'),
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
    | Capability → model map (the LOCKED v2 set, verified on arm64 2026-06-20; ONNX models
    | are arch-portable and the runtime lib is resolved per-arch, so this carries to x86_64/Netcup)
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
            'top_k' => -1, // -1 = return ALL categories (not just the top one) for a full moderation breakdown
        ],
        'summarize' => [
            // Abstractive summarisation, CPU-only via transformers-php (no GPU, no generative LLM).
            // distilbart-cnn-6-6 is small and fast-ish on CPU — "fun to play with", not SOTA prose.
            // One-line swap if a better small ONNX seq2seq turns up.
            'kind' => 'pipeline',
            'task' => 'summarization',
            'model' => 'Xenova/distilbart-cnn-6-6',
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
            // Moved to the Python vision sidecar (vision-sidecar/): Florence-2-base. The
            // ONNX/transformers-php image-to-text pipeline caps out at vit-gpt2, so a real
            // captioner has to live in Python — same pattern as transcribe. Crunch calls it
            // over the internal compose network (see the `vision` block below).
            'kind' => 'sidecar',
            'model' => env('CRUNCH_VISION_MODEL', 'microsoft/Florence-2-base'),
        ],
        // OCR and object detection are the same warm Florence-2 model, different task tokens.
        'ocr' => [
            'kind' => 'sidecar',
            'model' => env('CRUNCH_VISION_MODEL', 'microsoft/Florence-2-base'),
        ],
        'detect' => [
            'kind' => 'sidecar',
            'model' => env('CRUNCH_VISION_MODEL', 'microsoft/Florence-2-base'),
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
