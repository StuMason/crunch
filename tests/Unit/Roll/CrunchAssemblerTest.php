<?php

declare(strict_types=1);

use App\DataTransferObjects\Roll\Pack;
use App\DataTransferObjects\Roll\PackEvent;
use App\DataTransferObjects\Roll\PackManifest;
use App\Support\Roll\CrunchAssembler;
use App\Support\Roll\PackReader;

function assemblerPack(): Pack
{
    return (new PackReader)->read(dirname(__DIR__, 2).'/Fixtures/roll-pack');
}

/**
 * @return array{text: string, conf: float, box: array<int, int>}
 */
function ocrLine(string $text, int $x, int $y, int $w, int $h, float $conf = 90.0): array
{
    return ['text' => $text, 'conf' => $conf, 'box' => [$x, $y, $w, $h]];
}

it('builds the crunch.json envelope from a real pack', function () {
    $out = (new CrunchAssembler)->assemble(assemblerPack(), 'rec-test', [], []);

    expect($out)->toHaveKeys(['pack_id', 'duration_ms', 'fps', 'transcript', 'screen', 'events', 'moments', 'segments'])
        ->and($out['pack_id'])->toBe('rec-test')
        ->and($out['fps'])->toBe(30)
        ->and($out['events'])->toHaveCount(11)            // interactions only (cursor excluded)
        ->and($out['moments'])->toHaveCount(4)            // 2 app_switch + 2 click_on
        ->and($out['segments'])->not->toBeEmpty();
});

it('dedupes on-screen text lines into time-spans, splitting on gaps', function () {
    $frame = [ocrLine('Deploy', 10, 10, 80, 20), ocrLine('Settings', 10, 40, 90, 20)];
    $ocr = [
        0 => $frame,
        1000 => $frame,
        5000 => [ocrLine('Deploy', 10, 10, 80, 20)],   // reappears after a >gap absence
    ];

    $spans = (new CrunchAssembler)->assemble(assemblerPack(), 'p', $ocr, [], spanGapMs: 2500)['screen'];

    $deploy = array_values(array_filter($spans, fn ($s) => $s['text'] === 'Deploy'));
    $settings = array_values(array_filter($spans, fn ($s) => $s['text'] === 'Settings'));

    expect($deploy)->toHaveCount(2)
        ->and($deploy[0])->toMatchArray(['t_start' => 0, 't_end' => 1000])
        ->and($deploy[1])->toMatchArray(['t_start' => 5000, 't_end' => 5000])
        ->and($settings)->toHaveCount(1);
});

it('fuzzy-merges OCR jitter (case/punctuation) of the same line into one span', function () {
    $ocr = [
        0 => [ocrLine('Stop Recording', 10, 10, 200, 20, conf: 80.0)],
        1000 => [ocrLine('stop  recording.', 10, 10, 200, 20, conf: 95.0)],  // jittered read, higher conf
    ];

    $spans = (new CrunchAssembler)->assemble(assemblerPack(), 'p', $ocr, [], spanGapMs: 2500)['screen'];

    expect($spans)->toHaveCount(1)
        ->and($spans[0])->toMatchArray(['t_start' => 0, 't_end' => 1000])
        ->and($spans[0]['text'])->toBe('stop  recording.');  // highest-confidence read wins the display
});

it('discards top-of-frame OS chrome (the menu bar) when a frame height is known', function () {
    $ocr = [
        0 => [
            ocrLine('Arc File Edit View Spaces', 0, 5, 600, 18),   // menu bar, top of a 1080px frame
            ocrLine('Deploy', 10, 200, 80, 20),
        ],
    ];

    $spans = (new CrunchAssembler)->assemble(assemblerPack(), 'p', $ocr, [], frameHeight: 1080)['screen'];

    expect($spans)->toHaveCount(1)
        ->and($spans[0]['text'])->toBe('Deploy');
});

it('resolves a click to the OCR line under the cursor (ocr_at_click)', function () {
    $manifest = new PackManifest('0.0.14', 30, 0.0, 10000.0, ['id' => 1, 'x' => 0, 'y' => 0, 'w' => 1920, 'h' => 1080], 'screen.mp4', null, null, 0.0, 0.0, 'metadata.jsonl');
    $click = new PackEvent('click', 3000, 50, 20, 'left', 'Arc', 'win', [], []);
    $pack = new Pack('/tmp', $manifest, [$click]);

    // frame at the click's t_ms: a "Stop Recording" line whose box covers the click at (50,20)
    $ocr = [3000 => [ocrLine('Stop Recording', 10, 10, 215, 20)]];

    $event = (new CrunchAssembler)->assemble($pack, 'p', $ocr, [])['events'][0];

    expect($event['ocr_at_click'])->toBe('Stop Recording');
});

it('attaches the transcript window said around an event', function () {
    $words = [
        ['word' => 'hello', 't_ms' => 2000],
        ['word' => 'world', 't_ms' => 2400],
        ['word' => 'later', 't_ms' => 9000],
    ];

    $events = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], $words, saidWindowMs: 2500)['events'];
    $click = collect($events)->firstWhere('t_ms', 2316);

    expect($click['said'])->toBe('hello world');
});

it('keeps ax.label as on_screen_text and emits a click_on moment', function () {
    $manifest = new PackManifest('0.0.14', 30, 0.0, 10000.0, ['id' => 1, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100], 'screen.mp4', null, null, 0.0, 0.0, 'metadata.jsonl');
    $click = new PackEvent('click', 3000, 880, 410, 'left', 'Arc', 'Coolify', ['role' => 'AXButton', 'label' => 'Deploy'], []);
    $pack = new Pack('/tmp', $manifest, [$click]);

    $out = (new CrunchAssembler)->assemble($pack, 'p', [], []);

    expect($out['events'][0]['on_screen_text'])->toBe('Deploy')
        ->and($out['events'][0]['ax'])->toMatchArray(['role' => 'AXButton', 'label' => 'Deploy'])
        ->and($out['moments'])->toContain(['t_ms' => 3000, 'kind' => 'click_on', 'label' => 'Deploy', 'score' => 0.9, 'source' => 'telemetry']);
});

it('promotes a typed-text event and emits a type_text moment', function () {
    $manifest = new PackManifest('0.0.14', 30, 0.0, 10000.0, ['id' => 1, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100], 'screen.mp4', null, null, 0.0, 0.0, 'metadata.jsonl');
    $typed = new PackEvent('text', 5000, null, null, null, 'Arc', 'NotRobophobic', [], [], 'shop', 5300);
    $pack = new Pack('/tmp', $manifest, [$typed]);

    $out = (new CrunchAssembler)->assemble($pack, 'p', [], []);

    expect($out['events'][0])->toMatchArray(['t_ms' => 5000, 'type' => 'text', 'text' => 'shop'])
        ->and($out['moments'])->toContain(['t_ms' => 5000, 'kind' => 'type_text', 'label' => 'shop', 'score' => 0.8, 'source' => 'telemetry']);
});

it('does not emit a type_text moment for an empty typed string', function () {
    $manifest = new PackManifest('0.0.14', 30, 0.0, 10000.0, ['id' => 1, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100], 'screen.mp4', null, null, 0.0, 0.0, 'metadata.jsonl');
    $empty = new PackEvent('text', 5000, null, null, null, 'Arc', 'win', [], [], '   ');
    $pack = new Pack('/tmp', $manifest, [$empty]);

    $out = (new CrunchAssembler)->assemble($pack, 'p', [], []);

    expect(array_filter($out['moments'], fn ($m) => $m['kind'] === 'type_text'))->toBeEmpty();
});

it('emits a sys_transcript track only when sysaudio words are present', function () {
    $sys = [['word' => 'buy', 't_ms' => 1000], ['word' => 'now', 't_ms' => 1200]];

    $out = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], [], sysWords: $sys);
    expect($out['sys_transcript'])->toMatchArray(['text' => 'buy now'])
        ->and($out['sys_transcript']['words'])->toHaveCount(2);

    // absent entirely when the take has no system audio (mic-only pack is unchanged)
    expect((new CrunchAssembler)->assemble(assemblerPack(), 'p', [], []))->not->toHaveKey('sys_transcript');
});

it('carries per-word end times and confidence through to the transcript (AAI parity)', function () {
    $words = [['word' => 'hello', 't_ms' => 2000, 't_end_ms' => 2300, 'confidence' => 0.98]];
    $sys = [['word' => 'ping', 't_ms' => 1000, 't_end_ms' => 1150, 'confidence' => 0.41]];

    $out = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], $words, sysWords: $sys);

    expect($out['transcript']['words'][0])->toBe(['word' => 'hello', 't_ms' => 2000, 't_end_ms' => 2300, 'confidence' => 0.98])
        ->and($out['sys_transcript']['words'][0])->toBe(['word' => 'ping', 't_ms' => 1000, 't_end_ms' => 1150, 'confidence' => 0.41]);
});

it('builds on_camera spans from presence samples and emits an onset moment per span', function () {
    $samples = [
        ['t_ms' => 0, 'present' => true, 'score' => 0.9],
        ['t_ms' => 2000, 'present' => true, 'score' => 0.8],
        ['t_ms' => 4000, 'present' => false, 'score' => 0.2],   // ends span 1
        ['t_ms' => 6000, 'present' => true, 'score' => 0.85],   // starts span 2
        ['t_ms' => 8000, 'present' => true, 'score' => 0.7],
    ];

    $out = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], [], cameraSamples: $samples);

    expect($out['camera'])->toBe([
        ['t_start' => 0, 't_end' => 2000],
        ['t_start' => 6000, 't_end' => 8000],
    ]);

    $cam = array_values(array_filter($out['moments'], fn ($m) => $m['source'] === 'camera'));
    expect($cam)->toHaveCount(2)
        ->and($cam[0])->toMatchArray(['t_ms' => 0, 'kind' => 'on_camera', 'label' => 'presenter on camera', 'score' => 0.6])
        ->and($cam[1])->toMatchArray(['t_ms' => 6000, 'kind' => 'on_camera']);
});

it('omits the camera track entirely when no frame is on-camera', function () {
    $samples = [
        ['t_ms' => 0, 'present' => false, 'score' => 0.1],
        ['t_ms' => 2000, 'present' => false, 'score' => 0.2],
    ];

    $out = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], [], cameraSamples: $samples);

    expect($out)->not->toHaveKey('camera')
        ->and(array_filter($out['moments'], fn ($m) => $m['source'] === 'camera'))->toBeEmpty();
});

it('emits scored spoken-cue moments from the transcript on word boundaries', function () {
    $words = [
        ['word' => 'so', 't_ms' => 1000],
        ['word' => 'the', 't_ms' => 1200],
        ['word' => 'important', 't_ms' => 1400],
        ['word' => 'thing', 't_ms' => 1600],
        ['word' => 'is', 't_ms' => 1800],
        ['word' => 'breathe', 't_ms' => 5000],   // contains "the" but must NOT match "the key"
        ['word' => 'keys', 't_ms' => 5200],
    ];

    $moments = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], $words)['moments'];
    $cues = array_values(array_filter($moments, fn ($m) => $m['source'] === 'transcript'));

    expect($cues)->toHaveCount(1)
        ->and($cues[0])->toMatchArray(['t_ms' => 1200, 'kind' => 'cue_phrase', 'label' => 'the important thing', 'score' => 0.9]);
});

it('merges prosody moments into the timeline', function () {
    $prosody = [
        ['t_ms' => 7000, 'kind' => 'emphasis', 'label' => 'vocal emphasis', 'score' => 0.8, 'source' => 'audio'],
        ['t_ms' => 9000, 'kind' => 'pause', 'label' => 'pause (1.2s)', 'score' => 0.4, 'source' => 'audio'],
    ];

    $moments = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], [], prosodyMoments: $prosody)['moments'];
    $audio = array_values(array_filter($moments, fn ($m) => $m['source'] === 'audio'));

    expect($audio)->toHaveCount(2)
        ->and(array_column($moments, 't_ms'))->toBe(collect($moments)->pluck('t_ms')->sort()->values()->all());  // stays time-sorted
});
