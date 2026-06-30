<?php

declare(strict_types=1);

use App\DataTransferObjects\Roll\Pack;
use App\Support\Roll\PackReader;

/**
 * Slice 1 — the pack ingest + clock math, exercised against a REAL roll take
 * (rec-1782821238979, captured by capture-swift v0.0.14) so the parser is pinned to what
 * roll actually writes, not the aspirational schema.
 */
function fixturePath(string $sub = ''): string
{
    return dirname(__DIR__, 2).'/Fixtures'.($sub === '' ? '' : '/'.$sub);
}

function readFixturePack(): Pack
{
    return (new PackReader)->read(fixturePath('roll-pack'));
}

it('parses the real manifest into typed fields', function () {
    $m = readFixturePack()->manifest;

    expect($m->version)->toBe('0.0.14')
        ->and($m->fps)->toBe(30)
        ->and($m->t0)->toBe(306069.49669022002)
        ->and($m->durationMs)->toBeGreaterThan(18_000.0)
        ->and($m->display)->toMatchArray(['w' => 2560, 'h' => 1440, 'x' => 2560, 'y' => 0])
        ->and($m->screenFile)->toBe('screen.mp4')
        ->and($m->hasCamera())->toBeTrue()
        ->and($m->hasMic())->toBeTrue()
        ->and($m->metadataFile)->toBe('metadata.jsonl');
});

it('loads telemetry sorted on the shared clock and drops cursor noise from interactions', function () {
    $pack = readFixturePack();

    expect($pack->events)->toHaveCount(117)                 // 9 click + 2 app_focus + 106 cursor
        ->and($pack->events[0]->tMs)->toBe(0)               // sorted; first row is the t0 app_focus
        ->and($pack->interactions())->toHaveCount(11)       // cursor excluded
        ->and($pack->ofType('click'))->toHaveCount(9);
});

it('exposes accessibility context on clicks (the join s first-choice signal)', function () {
    $click = readFixturePack()->ofType('click')[0];

    expect($click->axRole())->toBe('AXScrollArea')
        ->and($click->app)->toBe('Arc')
        ->and($click->x)->toBeInt();
});

it('converts an event t_ms to the correct per-source seek seconds (offsets honoured)', function () {
    $m = readFixturePack()->manifest;
    $tMs = 2316;

    // screen is anchored at t0 -> seek == t_ms/1000 exactly
    expect($m->screenSecondsAt($tMs))->toBe(2.316);

    // mic/camera start AFTER t0 by their sync offsets -> their timelines shift back
    expect($m->micSecondsAt($tMs))->toEqualWithDelta((2316 - $m->micSyncOffsetMs) / 1000, 1e-9)
        ->and($m->cameraSecondsAt($tMs))->toEqualWithDelta((2316 - $m->cameraSyncOffsetMs) / 1000, 1e-9);
});

it('round-trips a transcript word time through the mic clock back to t_ms', function () {
    $m = readFixturePack()->manifest;

    // a word at 5.0s INTO mic.m4a lands at t0 + micSyncOffsetMs + 5s on the shared clock
    $tMs = $m->micTMsForSeconds(5.0);

    expect($m->micSecondsAt($tMs))->toEqualWithDelta(5.0, 1e-3);
});

it('rejects a directory with no manifest', function () {
    (new PackReader)->read(fixturePath());
})->throws(RuntimeException::class, 'manifest not found');
