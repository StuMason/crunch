<?php

declare(strict_types=1);

use App\Support\Roll\AudioProsody;

/**
 * A synthetic loudness envelope: -30 LUFS speech baseline, a louder -20 peak at 2.0–2.3s
 * (emphasis), and a -60 silence run at 5.0–6.0s (a 1s pause).
 *
 * @return list<array{t_ms: int, lufs: float}>
 */
function prosodyEnvelope(): array
{
    $env = [];
    for ($t = 0; $t <= 8000; $t += 100) {
        if ($t >= 2000 && $t <= 2300) {
            $v = -20.0;
        } elseif ($t >= 5000 && $t <= 6000) {
            $v = -60.0;
        } else {
            $v = -30.0;
        }
        $env[] = ['t_ms' => $t, 'lufs' => $v];
    }

    return $env;
}

it('detects a vocal-emphasis peak and a pause from the loudness envelope', function () {
    $moments = (new AudioProsody)->detectMoments(prosodyEnvelope(), 0);

    $emphasis = array_values(array_filter($moments, fn ($m) => $m['kind'] === 'emphasis'));
    $pause = array_values(array_filter($moments, fn ($m) => $m['kind'] === 'pause'));

    expect($emphasis)->toHaveCount(1)
        ->and($emphasis[0]['t_ms'])->toBe(2000)
        ->and($emphasis[0]['score'])->toBeGreaterThan(0.5)
        ->and($emphasis[0]['source'])->toBe('audio')
        ->and($pause)->toHaveCount(1)
        ->and($pause[0]['t_ms'])->toBe(5000)
        ->and($pause[0]['label'])->toContain('pause')
        ->and($pause[0]['score'])->toBeGreaterThan(0.0);
});

it('shifts prosody moments by the mic sync offset onto the shared clock', function () {
    $emphasis = collect((new AudioProsody)->detectMoments(prosodyEnvelope(), 500))->firstWhere('kind', 'emphasis');

    expect($emphasis['t_ms'])->toBe(2500);   // 2000 + 500ms offset
});

it('returns nothing when the track is entirely silent', function () {
    $silent = array_map(fn ($t) => ['t_ms' => $t, 'lufs' => -90.0], range(0, 3000, 100));

    expect((new AudioProsody)->detectMoments($silent, 0))->toBe([]);
});

it('parses an ffmpeg ametadata loudness envelope', function () {
    $text = "frame:0 pts:0 pts_time:0\nlavfi.r128.M=-120.7\nframe:1 pts:4800 pts_time:0.1\nlavfi.r128.M=-30.5\n";

    $env = AudioProsody::parseEnvelope($text);

    expect($env)->toHaveCount(2)
        ->and($env[0])->toMatchArray(['t_ms' => 0, 'lufs' => -120.7])
        ->and($env[1])->toMatchArray(['t_ms' => 100, 'lufs' => -30.5]);
});
