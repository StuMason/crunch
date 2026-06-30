<?php

declare(strict_types=1);

use App\DataTransferObjects\Roll\Pack;
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
