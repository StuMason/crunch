<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessPackJob;
use App\Models\InferenceJob;
use App\Support\JobPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PackController extends Controller
{
    /**
     * Process a roll pack (async)
     *
     * Crunch a `roll` take into a queryable **crunch.json** — the join of
     * `event × on-screen-text × words-said` on the take's shared clock, the index an editing
     * agent reads instead of watching the footage.
     *
     * **Send:** `multipart/form-data` with a `pack` file — a tar/tar.gz of the take directory
     * (its `manifest.json`, `metadata.jsonl`, `screen.mp4`, and optional `mic.m4a`/`camera.mp4`).
     *
     * **Get back:** `202 Accepted` with a job — note its `id` and poll `GET /jobs/{id}` until
     * `status` is `completed`. The `result` is the crunch.json:
     *
     * - `transcript` — full text + per-word timestamps.
     * - `screen` — on-screen-text time-spans (line-level OCR, deduped): what was visible, when.
     * - `events` — each interaction joined with what was clicked (its accessibility label **and**
     *   the pixel-precise `ocr_at_click` line under the cursor) and what was being `said`.
     * - `moments` — scored edit landmarks, each tagged with a `source`: `telemetry`
     *   (`click_on`, `app_switch`), `transcript` (`cue_phrase`), `audio` (`emphasis`, `pause`).
     * - `segments` — a beat-by-beat outline (title, keywords, summary), cut at app switches and
     *   long pauses.
     *
     * It's an index, not a dump — no per-frame data, no embeddings.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'pack' => ['required', 'file', 'max:307200'],   // up to ~300 MB take archive (full packs incl. camera)
        ]);

        $file = $request->file('pack');

        // Derive a display id from the upload name, but treat it as hostile input: take only the
        // basename, strip the archive suffix, and allow nothing but safe filename characters — it
        // ends up in the stored crunch.json, never in a filesystem path.
        $base = preg_replace('/\.(tar\.gz|tgz|tar|zip)$/i', '', basename((string) $file->getClientOriginalName()));
        $packId = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $base), '_');

        $job = InferenceJob::create([
            'type' => 'pack',
            'status' => InferenceJob::STATUS_QUEUED,
            'model' => 'roll-pack-v1',
        ]);

        // Store under a server-generated name only — the client filename never touches the path.
        // Resolve the absolute path via the disk (its root is storage/app/private here, not
        // storage/app), so the worker reads exactly where the upload landed.
        $stored = $file->storeAs('packs', "{$job->id}-upload");
        if ($stored === false) {
            $job->delete();
            abort(500, 'Could not store the uploaded pack.');
        }

        ProcessPackJob::dispatch($job->id, Storage::path($stored), $packId !== '' ? $packId : $job->uid);

        return response()->json(JobPresenter::present($job), 202);
    }
}
