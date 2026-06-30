<?php

declare(strict_types=1);

use App\DataTransferObjects\Roll\Pack;
use App\DataTransferObjects\Roll\PackEvent;
use App\DataTransferObjects\Roll\PackManifest;
use App\Support\Roll\FrameSampler;
use App\Support\Roll\PackReader;

function samplerPack(): Pack
{
    return (new PackReader)->read(dirname(__DIR__, 2).'/Fixtures/roll-pack');
}

it('samples interaction times plus a baseline cadence, sorted and in-bounds', function () {
    $pack = samplerPack();
    $duration = (int) round($pack->manifest->durationMs);

    $times = (new FrameSampler)->sample($pack, cadenceMs: 1000);

    expect($times)->not->toBeEmpty()
        ->and($times[0])->toBe(0)                               // baseline starts at t0
        ->and($times)->toEqual(collect($times)->sort()->values()->all())  // sorted
        ->and(min($times))->toBeGreaterThanOrEqual(0)
        ->and(max($times))->toBeLessThanOrEqual($duration);
});

it('includes a real click timestamp and merges near-duplicate frames', function () {
    $times = (new FrameSampler)->sample(samplerPack(), cadenceMs: 1000, mergeWithinMs: 200);

    // the first click in the fixture is at t_ms=2316 — far enough from the 2000/3000 baseline
    // ticks to survive the merge, so it must be sampled.
    expect($times)->toContain(2316);

    // no two sampled frames sit within the merge window
    for ($i = 1; $i < count($times); $i++) {
        expect($times[$i] - $times[$i - 1])->toBeGreaterThan(200);
    }
});

it('keeps frame count modest — an index, not a per-frame dump', function () {
    // ~18s take at 1fps baseline + 11 interactions should be tens of frames, not hundreds.
    $times = (new FrameSampler)->sample(samplerPack(), cadenceMs: 1000);

    expect(count($times))->toBeLessThan(40);
});

it('caps total frames on a long take instead of sampling at 1fps', function () {
    // a 10-minute take would be ~650 frames at 1fps — must stay under the cap by widening cadence
    $m = new PackManifest('x', 30, 0.0, 600_000.0, ['id' => 0, 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 0], 'screen.mp4', null, null, 0.0, 0.0, 'metadata.jsonl');
    $events = array_map(
        fn (int $t) => new PackEvent('click', $t, 0, 0, 'left', null, null, [], []),
        [1000, 90_000, 300_000, 540_000],
    );

    $times = (new FrameSampler)->sample(new Pack('/tmp', $m, $events), cadenceMs: 1000, maxFrames: 250);

    expect(count($times))->toBeLessThanOrEqual(250)
        ->and($times)->toContain(1000);   // an interaction far from any baseline tick survives
});
