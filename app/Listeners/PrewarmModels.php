<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Inference\InferenceManager;
use Throwable;

/**
 * Pre-load configured capabilities into the warm InferenceManager when an Octane
 * worker boots, so the first request is already fast (no cold model load).
 *
 * Each worker holds its own copy of the models in memory, so keep `crunch.warm`
 * to the hot, lightweight capabilities and let heavy ones (e.g. Whisper) lazy-load.
 */
class PrewarmModels
{
    public function __construct(private readonly InferenceManager $inference) {}

    public function handle(object $event): void
    {
        foreach ((array) config('crunch.warm', []) as $capability) {
            try {
                // Sidecar-backed capabilities (caption/ocr/detect/transcribe) run in the
                // Python sidecars, not the in-process InferenceManager — there is nothing
                // to warm here, and asking for their engine would throw. Skip them.
                if (($this->inference->config($capability)['kind'] ?? null) === 'sidecar') {
                    continue;
                }

                $this->inference->engine($capability);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
