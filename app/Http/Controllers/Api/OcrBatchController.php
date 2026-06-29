<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\OcrBatchJob;
use App\Models\InferenceJob;
use App\Support\JobPresenter;
use App\Support\MediaResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OcrBatchController extends Controller
{
    /**
     * A single `/ocr/batch` job won't accept more crops than this — bounds how long one
     * job can occupy the queue worker (crops are OCR'd strictly one at a time).
     */
    private const MAX_CROPS = 200;

    /**
     * Batch OCR many crops (async)
     *
     * Read the text out of many image crops in one job. OCR is ~8s/call and the live `/ocr`
     * endpoint sheds concurrent calls to `429` by design — so a `roll` pack's worth of
     * click-crops would otherwise mean minutes of hand-throttled sequential calls. Submit
     * them all here instead: you get a **job** back immediately, then poll `GET /jobs/{id}`
     * until `status` is `completed` and read `result.results`.
     *
     * **Send:** `application/json` with a `crops` array (1–200 items). Each crop is
     * `{"box": [x, y, w, h], "image": "<base64>"}` — `image` is the crop's bytes as base64
     * (a `data:` URI is fine); `box` is optional and echoed back verbatim so you can map
     * each result to where it came from (it can be any JSON — an array, an object, an id).
     *
     * **Get back:** `202 Accepted` with a job — note its `id` and poll it. When complete,
     * `result.results` is an array (in submission order) of `{box, text, error}`: `box` as
     * you sent it, the OCR'd `text`, and `error` non-null only for crops that failed (one
     * bad crop never fails the whole job). The server drains the batch within the vision
     * inflight cap, so you never have to back off around `429`s yourself.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'crops' => ['required', 'array', 'min:1', 'max:'.self::MAX_CROPS],
            'crops.*.image' => ['required', 'string'],   // the crop bytes as base64 / data-URI
            'crops.*.box' => ['sometimes'],              // echoed back verbatim (e.g. [x,y,w,h])
        ]);

        // Decode + persist every crop up front: a malformed crop fails fast as a 422 (before
        // any job is queued), and the worker reads the bytes from disk — not from a bloated
        // job payload — exactly as the transcribe path passes a file, not the audio.
        $items = [];
        foreach ((array) $request->input('crops') as $i => $crop) {
            $items[] = [
                'box' => $crop['box'] ?? null,
                'path' => MediaResolver::persistBase64((string) ($crop['image'] ?? ''), "crops[{$i}].image"),
            ];
        }

        $job = InferenceJob::create([
            'type' => 'ocr-batch',
            'status' => InferenceJob::STATUS_QUEUED,
            'model' => config('crunch.models.ocr.model'),
        ]);

        OcrBatchJob::dispatch($job->id, $items);

        return response()->json(JobPresenter::present($job), 202);
    }
}
