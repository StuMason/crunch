<?php

declare(strict_types=1);

use App\DataTransferObjects\Roll\Pack;
use App\DataTransferObjects\Roll\PackEvent;
use App\DataTransferObjects\Roll\PackManifest;
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

it('parses the system-audio track (captured for future processing)', function () {
    $m = readFixturePack()->manifest;

    expect($m->hasSysAudio())->toBeTrue()
        ->and($m->sysAudioFile)->toBe('sysaudio.m4a')
        ->and($m->sysAudioSyncOffsetMs)->toBeGreaterThan(0.0)
        // seeks like the other post-t0 tracks (offset shifts its timeline back)
        ->and($m->sysAudioSecondsAt(2316))->toEqualWithDelta((2316 - $m->sysAudioSyncOffsetMs) / 1000, 1e-9);
});

it('round-trips a sysaudio word time through the sysaudio clock back to t_ms', function () {
    $m = readFixturePack()->manifest;

    // a word at 5.0s INTO sysaudio.m4a lands at t0 + sysAudioSyncOffsetMs + 5s on the shared clock
    $tMs = $m->sysAudioTMsForSeconds(5.0);

    expect($m->sysAudioSecondsAt($tMs))->toEqualWithDelta(5.0, 1e-3);
});

it('treats a take with no sysaudio as not having one', function () {
    $m = PackManifest::fromArray([
        'version' => 'x', 'fps' => 30, 't0' => 0, 'durationMs' => 1, 'display' => [],
        'screen' => ['file' => 'screen.mp4', 'firstPTS' => 0],
    ]);

    expect($m->hasSysAudio())->toBeFalse()
        ->and($m->sysAudioFile)->toBeNull();
});

it('discovers roll pre-rendered keyframes keyed by screen-clock t_ms, ignoring non-numeric names', function () {
    $pack = readFixturePack();

    expect($pack->keyframes)->toHaveCount(2)                // 0.png + 2316.png; thumbnail.png ignored
        ->and(array_keys($pack->keyframes))->toBe([0, 2316]) // ascending
        ->and($pack->keyframes[0])->toEndWith('keyframes/0.png');
});

it('promotes a typed-text event (text + end_ms) from metadata', function () {
    $e = PackEvent::fromArray([
        'type' => 'text', 't_ms' => 191766, 'text' => 'shop', 'end_ms' => 193109, 'app' => 'Arc',
    ]);

    expect($e->type)->toBe('text')
        ->and($e->text)->toBe('shop')
        ->and($e->endMs)->toBe(193109)
        ->and($e->isInteraction())->toBeTrue();      // typed text is an interaction, not cursor noise
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

it('neutralizes path traversal in untrusted manifest file fields', function () {
    $m = PackManifest::fromArray([
        'version' => 'x', 'fps' => 30, 't0' => 0, 'durationMs' => 1, 'display' => [],
        'screen' => ['file' => '../../../etc/passwd', 'firstPTS' => 0],
        'mic' => ['file' => '/etc/shadow', 'firstPTS' => 0],
        'metadata' => '../evil.jsonl',
    ]);

    expect($m->screenFile)->toBe('passwd')
        ->and($m->micFile)->toBe('shadow')
        ->and($m->metadataFile)->toBe('evil.jsonl');

    // and absolutePath can never escape the pack directory, even given a hostile value
    $pack = new Pack('/var/packs/abc', $m, []);
    expect($pack->absolutePath('../../etc/hosts'))->toBe('/var/packs/abc/hosts');
});
