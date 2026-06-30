<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\Pack;

/**
 * Decides WHICH screen-frame timestamps the pack processor should pull and OCR.
 *
 * v1 strategy (no embeddings/RAG yet): sample at every interaction (the moments the editor
 * cares about — a click, a key, an app switch) PLUS a coarse baseline cadence so persistent
 * on-screen text that appears without an event is still captured. It deliberately does NOT do
 * dense per-frame sampling — that was a retrieval need and returns with RAG in Phase 2. The
 * span-dedup in the join then collapses text that's on screen across many of these frames into
 * a single time-span, keeping crunch.json an index rather than a data dump.
 *
 * Pure + deterministic so it can be unit-tested without ffmpeg or any media.
 */
class FrameSampler
{
    /**
     * @param  int  $cadenceMs  baseline gap between safety-net frames (default ~1 fps)
     * @param  int  $mergeWithinMs  collapse timestamps closer than this into one frame
     * @return list<int> sorted, unique screen-clock t_ms to extract
     */
    public function sample(Pack $pack, int $cadenceMs = 1000, int $mergeWithinMs = 200): array
    {
        $duration = (int) round($pack->manifest->durationMs);

        $times = [];
        foreach ($pack->interactions() as $event) {
            $times[] = $event->tMs;
        }

        for ($t = 0; $t <= $duration; $t += $cadenceMs) {
            $times[] = $t;
        }

        return $this->normalize($times, $duration, $mergeWithinMs);
    }

    /**
     * Sort, clamp to the take's bounds, and merge near-duplicate timestamps (an event that
     * lands ~on a baseline tick shouldn't extract the same frame twice).
     *
     * @param  list<int>  $times
     * @return list<int>
     */
    private function normalize(array $times, int $duration, int $mergeWithinMs): array
    {
        $times = array_filter($times, fn (int $t): bool => $t >= 0 && $t <= $duration);
        sort($times);

        $merged = [];
        foreach ($times as $t) {
            if ($merged === [] || $t - $merged[count($merged) - 1] > $mergeWithinMs) {
                $merged[] = $t;
            }
        }

        return $merged;
    }
}
