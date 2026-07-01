<?php

declare(strict_types=1);

use App\Actions\Roll\ProcessPack;

/**
 * The keyframe/extraction split: given the frames the sampler wants and the keyframes roll
 * already pre-rendered, only the uncovered times get extracted from screen.mp4 (the keyframes
 * are OCR'd directly). Pure, so it's exercised without ffmpeg or the OCR sidecar.
 */
function processPack(): ProcessPack
{
    return app(ProcessPack::class);
}

it('extracts every sampled frame when the take has no keyframes', function () {
    expect(processPack()->timesNeedingExtraction([0, 1000, 2000], []))
        ->toBe([0, 1000, 2000]);
});

it('skips extracting a sampled time that a keyframe already covers (exact match)', function () {
    expect(processPack()->timesNeedingExtraction([0, 1000, 2000], [1000]))
        ->toBe([0, 2000]);
});

it('skips a sampled time when a keyframe lands within the merge window', function () {
    // keyframe 1120 is 120ms off the 1000 tick (< 200ms) -> covered; 2000 is far -> extracted
    expect(processPack()->timesNeedingExtraction([0, 1000, 2000], [1120], mergeWithinMs: 200))
        ->toBe([0, 2000]);
});

it('still extracts a sampled time when the nearest keyframe is outside the merge window', function () {
    expect(processPack()->timesNeedingExtraction([1000], [1300], mergeWithinMs: 200))
        ->toBe([1000]);
});
