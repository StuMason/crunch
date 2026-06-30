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
