<?php

declare(strict_types=1);

namespace App\Support\Roll;

use Illuminate\Support\Facades\Process;

/**
 * Reads editorial signal out of the mic track that telemetry alone can't see: where the speaker
 * got *emphatic* (loud, leaning-in) and where they *paused* (a natural cut point). These become
 * scored `moments` on the shared clock so an editor can rank or threshold them.
 *
 * Loudness comes from ffmpeg's EBU R128 momentary-loudness meter (a 400ms sliding window sampled
 * every 100ms) — no new dependency, and it's perceptual rather than raw amplitude. The ffmpeg call
 * is the only I/O; parsing and peak/pause detection are pure so the editorial logic is unit-tested
 * against a synthetic envelope.
 *
 * @phpstan-type LoudnessSample array{t_ms: int, lufs: float}
 * @phpstan-type ProsodyMoment array{t_ms: int, kind: string, label: string, score: float, source: string}
 */
class AudioProsody
{
    /** Samples this quiet (LUFS) are treated as silence, not speech. */
    private const SILENCE_FLOOR = -45.0;

    /** A sample is "emphasis" when it's this many loudness units above the speaker's median. */
    private const EMPHASIS_DELTA = 4.0;

    /** Loudness units above median that map an emphasis peak to a full 1.0 score. */
    private const EMPHASIS_SCALE = 12.0;

    /** Emphasis peaks closer than this collapse to the loudest one. */
    private const EMPHASIS_MERGE_MS = 1200;

    /** A silence run shorter than this isn't a meaningful pause. */
    private const MIN_PAUSE_MS = 600;

    /** Pause length that maps to a full 1.0 score. */
    private const PAUSE_SCALE_MS = 3000;

    /** Keep at most this many of each kind — an editorial shortlist, not every blip. */
    private const MAX_PER_KIND = 25;

    public function __construct(private readonly string $ffmpeg = 'ffmpeg') {}

    /**
     * @return list<ProsodyMoment> emphasis + pause moments, shifted onto the shared clock
     */
    public function analyze(string $audioPath, int $micSyncOffsetMs): array
    {
        return $this->detectMoments($this->loudnessEnvelope($audioPath), $micSyncOffsetMs);
    }

    /**
     * Run ffmpeg's R128 meter and read back the momentary-loudness envelope. The metadata is printed
     * to a temp file (stdout is taken by the null muxer); a failed/empty run yields no envelope and
     * so no prosody moments — never fatal to the job.
     *
     * @return list<LoudnessSample> mic-clock t_ms => momentary LUFS
     */
    private function loudnessEnvelope(string $audioPath): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'r128_');
        if ($tmp === false) {
            return [];
        }

        try {
            $result = Process::run([
                $this->ffmpeg, '-hide_banner', '-nostats', '-i', $audioPath,
                '-af', "ebur128=metadata=1,ametadata=print:key=lavfi.r128.M:file={$tmp}",
                '-f', 'null', '-',
            ]);

            if (! $result->successful() || ! is_file($tmp)) {
                return [];
            }

            return self::parseEnvelope((string) file_get_contents($tmp));
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Parse ffmpeg `ametadata=print` output (alternating `pts_time:…` then `lavfi.r128.M=…` lines)
     * into a loudness envelope.
     *
     * @return list<LoudnessSample>
     */
    public static function parseEnvelope(string $text): array
    {
        $out = [];
        $t = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/pts_time:([\d.]+)/', $line, $m) === 1) {
                $t = (float) $m[1];
            } elseif ($t !== null && preg_match('/lavfi\.r128\.M=(-?[\d.]+)/', $line, $m) === 1) {
                $out[] = ['t_ms' => (int) round($t * 1000), 'lufs' => (float) $m[1]];
            }
        }

        return $out;
    }

    /**
     * @param  list<LoudnessSample>  $envelope
     * @return list<ProsodyMoment>
     */
    public function detectMoments(array $envelope, int $micSyncOffsetMs): array
    {
        $speech = array_values(array_filter(
            array_map(fn (array $s): float => $s['lufs'], $envelope),
            fn (float $v): bool => $v > self::SILENCE_FLOOR,
        ));
        if ($speech === []) {
            return [];
        }

        $median = $this->median($speech);

        $moments = array_merge(
            $this->emphasisMoments($envelope, $median, $micSyncOffsetMs),
            $this->pauseMoments($envelope, $micSyncOffsetMs),
        );

        usort($moments, fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);

        return $moments;
    }

    /**
     * Loudness peaks above the speaker's median — collapsed so one emphatic phrase is one moment.
     *
     * @param  list<LoudnessSample>  $envelope
     * @return list<ProsodyMoment>
     */
    private function emphasisMoments(array $envelope, float $median, int $micSyncOffsetMs): array
    {
        $threshold = $median + self::EMPHASIS_DELTA;

        // Peak of each contiguous above-threshold run.
        $peaks = [];
        $bestT = null;
        $bestV = -INF;
        foreach ($envelope as $s) {
            if ($s['lufs'] > $threshold) {
                if ($s['lufs'] > $bestV) {
                    $bestV = $s['lufs'];
                    $bestT = $s['t_ms'];
                }
            } elseif ($bestT !== null) {
                $peaks[] = ['t_ms' => $bestT, 'lufs' => $bestV];
                $bestT = null;
                $bestV = -INF;
            }
        }
        if ($bestT !== null) {
            $peaks[] = ['t_ms' => $bestT, 'lufs' => $bestV];
        }

        // Merge peaks that sit within EMPHASIS_MERGE_MS, keeping the loudest.
        $merged = [];
        foreach ($peaks as $p) {
            $last = $merged === [] ? null : array_key_last($merged);
            if ($last !== null && $p['t_ms'] - $merged[$last]['t_ms'] <= self::EMPHASIS_MERGE_MS) {
                if ($p['lufs'] > $merged[$last]['lufs']) {
                    $merged[$last] = $p;
                }

                continue;
            }
            $merged[] = $p;
        }

        $moments = [];
        foreach ($merged as $p) {
            $tMs = $p['t_ms'] + $micSyncOffsetMs;
            if ($tMs < 0) {
                continue;
            }
            $score = $this->clamp(($p['lufs'] - $median) / self::EMPHASIS_SCALE);
            $moments[] = [
                't_ms' => $tMs,
                'kind' => 'emphasis',
                'label' => 'vocal emphasis',
                'score' => $score,
                'source' => 'audio',
            ];
        }

        return $this->topByScore($moments);
    }

    /**
     * Silence runs long enough to be a deliberate pause — a natural cut point, timed at its start.
     *
     * @param  list<LoudnessSample>  $envelope
     * @return list<ProsodyMoment>
     */
    private function pauseMoments(array $envelope, int $micSyncOffsetMs): array
    {
        $moments = [];
        $runStart = null;
        $runEnd = null;
        foreach ($envelope as $s) {
            if ($s['lufs'] < self::SILENCE_FLOOR) {
                $runStart ??= $s['t_ms'];
                $runEnd = $s['t_ms'];
            } elseif ($runStart !== null && $runEnd !== null) {
                $this->pushPause($moments, $runStart, $runEnd, $micSyncOffsetMs);
                $runStart = null;
                $runEnd = null;
            }
        }
        if ($runStart !== null && $runEnd !== null) {
            $this->pushPause($moments, $runStart, $runEnd, $micSyncOffsetMs);
        }

        return $this->topByScore($moments);
    }

    /**
     * @param  list<ProsodyMoment>  $moments
     */
    private function pushPause(array &$moments, int $startMs, int $endMs, int $micSyncOffsetMs): void
    {
        $durationMs = $endMs - $startMs;
        if ($durationMs < self::MIN_PAUSE_MS) {
            return;
        }

        $tMs = $startMs + $micSyncOffsetMs;
        if ($tMs < 0) {
            return;
        }

        $moments[] = [
            't_ms' => $tMs,
            'kind' => 'pause',
            'label' => sprintf('pause (%.1fs)', $durationMs / 1000),
            'score' => $this->clamp($durationMs / self::PAUSE_SCALE_MS),
            'source' => 'audio',
        ];
    }

    /**
     * @param  list<ProsodyMoment>  $moments
     * @return list<ProsodyMoment>
     */
    private function topByScore(array $moments): array
    {
        usort($moments, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($moments, 0, self::MAX_PER_KIND);
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    private function clamp(float $v, float $min = 0.1, float $max = 1.0): float
    {
        return round(max($min, min($max, $v)), 2);
    }
}
