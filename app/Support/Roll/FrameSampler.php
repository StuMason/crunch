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
     * @param  int  $cadenceMs  smallest baseline gap between safety-net frames (~1 fps)
     * @param  int  $mergeWithinMs  collapse timestamps closer than this into one frame
     * @param  int  $maxFrames  hard cap on total frames — a 10-min take at 1 fps would mean
     *                          ~650 sequential OCR calls and a wall of noisy spans, so the
     *                          baseline cadence widens for long takes to stay under this.
     *                          Interaction frames (the moments that matter) are always kept.
     * @return list<int> sorted, unique screen-clock t_ms to extract
     */
    public function sample(Pack $pack, int $cadenceMs = 1000, int $mergeWithinMs = 200, int $maxFrames = 250): array
    {
        $duration = (int) round($pack->manifest->durationMs);

        $times = [];
        foreach ($pack->interactions() as $event) {
            $times[] = $event->tMs;
        }

        // Reserve frames for interactions, spend the rest on a baseline whose cadence stretches
        // to fit the budget — a long take samples sparsely instead of blowing the frame cap.
        $budget = max(1, $maxFrames - count($times));
        $effectiveCadence = max($cadenceMs, (int) ceil(max(1, $duration) / $budget));

        for ($t = 0; $t <= $duration; $t += $effectiveCadence) {
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
