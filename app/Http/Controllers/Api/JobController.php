<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InferenceJob;
use App\Support\JobPresenter;
use Illuminate\Http\JsonResponse;

class JobController extends Controller
{
    /**
     * Check an async job
     *
     * Poll this with the `id` you got from an async endpoint (`/transcribe`, `/ocr/batch`).
     * While it's running `status` is `queued` or `processing`; when done it's `completed`
     * and `result` holds the output — its shape depends on the job `type`:
     * - `transcribe` → `result.text` plus `result.words` (word-level timestamps).
     * - `ocr-batch` → `result.results`, an array of `{box, text, error}` in submission order.
     *
     * On failure, `status` is `failed` with an `error`. (Individual batch-OCR crops that
     * fail surface a per-crop `error` instead — the job still completes.)
     */
    public function show(InferenceJob $job): JsonResponse
    {
        return response()->json(JobPresenter::present($job));
    }
}
