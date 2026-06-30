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

it('builds the crunch.json envelope from a real pack', function () {
    $out = (new CrunchAssembler)->assemble(assemblerPack(), 'rec-test', [], []);

    expect($out)->toHaveKeys(['pack_id', 'duration_ms', 'fps', 'transcript', 'screen', 'events', 'moments'])
        ->and($out['pack_id'])->toBe('rec-test')
        ->and($out['fps'])->toBe(30)
        ->and($out['duration_ms'])->toBeGreaterThan(18000)
        ->and($out['events'])->toHaveCount(11)            // interactions only (cursor excluded)
        ->and($out['moments'])->toHaveCount(4);           // 2 app_switch + 2 click_on (real ax-labelled clicks)

    // the real take captured clicks on roll's own Rec / Stop buttons, with their ax labels
    expect(collect($out['moments'])->pluck('label'))->toContain('Stop Recording', '● Rec');
});

it('dedupes persistent on-screen text into time-spans, splitting on gaps', function () {
    $ocr = [
        0 => "Deploy\nSettings",
        1000 => "Deploy\nSettings",
        5000 => 'Deploy',                                  // reappears after a >gap absence
    ];

    $spans = (new CrunchAssembler)->assemble(assemblerPack(), 'p', $ocr, [], spanGapMs: 2500)['screen'];

    $deploy = array_values(array_filter($spans, fn ($s) => $s['text'] === 'Deploy'));
    $settings = array_values(array_filter($spans, fn ($s) => $s['text'] === 'Settings'));

    expect($deploy)->toHaveCount(2)                        // [0..1000] and [5000..5000]
        ->and($deploy[0])->toMatchArray(['t_start' => 0, 't_end' => 1000])
        ->and($deploy[1])->toMatchArray(['t_start' => 5000, 't_end' => 5000])
        ->and($settings)->toHaveCount(1)
        ->and($settings[0])->toMatchArray(['t_start' => 0, 't_end' => 1000]);
});

it('attaches the transcript window said around an event', function () {
    $words = [
        ['word' => 'hello', 't_ms' => 2000],
        ['word' => 'world', 't_ms' => 2400],
        ['word' => 'later', 't_ms' => 9000],
    ];

    $events = (new CrunchAssembler)->assemble(assemblerPack(), 'p', [], $words, saidWindowMs: 2500)['events'];
    $click = collect($events)->firstWhere('t_ms', 2316);   // the real click at 2316ms

    expect($click['said'])->toBe('hello world');           // 'later' at 9000 is outside the window
});

it('joins ax.label first and emits a click_on moment', function () {
    $manifest = new PackManifest('0.0.14', 30, 0.0, 10000.0, ['id' => 1, 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100], 'screen.mp4', null, null, 0.0, 0.0, 'metadata.jsonl');
    $click = new PackEvent('click', 3000, 880, 410, 'left', 'Arc', 'Coolify', ['role' => 'AXButton', 'label' => 'Deploy'], []);
    $pack = new Pack('/tmp', $manifest, [$click]);

    $out = (new CrunchAssembler)->assemble($pack, 'p', [], []);

    expect($out['events'][0]['on_screen_text'])->toBe('Deploy')
        ->and($out['events'][0]['ax'])->toMatchArray(['role' => 'AXButton', 'label' => 'Deploy'])
        ->and($out['moments'])->toContain(['t_ms' => 3000, 'kind' => 'click_on', 'label' => 'Deploy', 'score' => 0.9]);
});
