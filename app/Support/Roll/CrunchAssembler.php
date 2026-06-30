<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\Pack;

/**
 * Assembles the lean `crunch.json` an editing agent reads — the join of
 * `event × on-screen-text × words-said` on the pack's shared clock.
 *
 * Works from line-level OCR (each reading-order line with a pixel box + mean confidence), which is
 * what makes the screen side trustworthy:
 *  - `screen` spans are built from the OCR lines, fuzzy-deduped into {text, t_start, t_end}
 *    time-spans — an index of what was on screen when, not a per-frame dump. Near-identical reads
 *    of the same line across frames collapse to one span (keyed on a normalised form), and the
 *    cleanest-confidence read wins the display text.
 *  - `ocr_at_click` resolves a click to the exact line whose box covers the cursor. roll emits
 *    click coords in screen.mp4 pixel space (the same space as the OCR boxes), so it's a direct hit.
 *  - top-of-frame OS chrome (the macOS menu bar) is discarded via a relative pixel cutoff, so the
 *    clock/menu-bar line stops polluting the index.
 *
 * Pure: takes already-computed OCR lines per frame + transcript words (on the shared clock),
 * returns the output array. No I/O, so the whole join is unit-testable without sidecars.
 *
 * @phpstan-type OcrLine array{text: string, conf: float, box: array<int, int>}
 */
class CrunchAssembler
{
    /**
     * Lines whose top edge sits within this fraction of the frame height are treated as the OS menu
     * bar / top chrome and dropped from the screen index.
     */
    private const TOP_CHROME_FRACTION = 0.03;

    /**
     * @param  array<int, list<OcrLine>>  $ocrLinesByFrame  screen-clock t_ms => OCR lines
     * @param  list<array{word: string, t_ms: int}>  $words  transcript words on the shared clock
     * @return array<string, mixed> the crunch.json structure
     */
    public function assemble(
        Pack $pack,
        string $packId,
        array $ocrLinesByFrame,
        array $words,
        int $saidWindowMs = 2500,
        int $spanGapMs = 2500,
        int $frameHeight = 0,
    ): array {
        return [
            'pack_id' => $packId,
            'duration_ms' => (int) round($pack->manifest->durationMs),
            'fps' => $pack->manifest->fps,
            'transcript' => [
                'text' => trim(implode(' ', array_column($words, 'word'))),
                'words' => $words,
            ],
            'screen' => $this->textSpans($ocrLinesByFrame, $spanGapMs, $frameHeight),
            'events' => $this->events($pack, $ocrLinesByFrame, $words, $saidWindowMs, $frameHeight),
            'moments' => $this->moments($pack),
        ];
    }

    /**
     * Collapse per-frame OCR lines into fuzzy-deduped on-screen-text spans. Each distinct line
     * (keyed on a normalised form, so OCR jitter and punctuation don't fork it) becomes one or more
     * {text, t_start, t_end} spans — seen across consecutive frames = one span; gone for longer than
     * $gapMs then back = a new span. The display text is the highest-confidence read of the line.
     *
     * @param  array<int, list<OcrLine>>  $ocrLinesByFrame
     * @return list<array{text: string, t_start: int, t_end: int}>
     */
    private function textSpans(array $ocrLinesByFrame, int $gapMs, int $frameHeight): array
    {
        ksort($ocrLinesByFrame);

        /** @var array<string, array{display: string, conf: float, times: list<int>}> $byLine */
        $byLine = [];
        foreach ($ocrLinesByFrame as $tMs => $lines) {
            foreach ($this->visibleLines($lines, $frameHeight) as $line) {
                $key = $this->normalizeKey($line['text']);
                if ($key === '') {
                    continue;
                }
                if (! isset($byLine[$key])) {
                    $byLine[$key] = ['display' => $line['text'], 'conf' => $line['conf'], 'times' => []];
                } elseif ($line['conf'] > $byLine[$key]['conf']) {
                    $byLine[$key]['display'] = $line['text'];
                    $byLine[$key]['conf'] = $line['conf'];
                }
                $byLine[$key]['times'][] = (int) $tMs;
            }
        }

        $spans = [];
        foreach ($byLine as $entry) {
            $times = $entry['times'];
            sort($times);
            $start = $prev = $times[0];
            foreach (array_slice($times, 1) as $t) {
                if ($t - $prev > $gapMs) {
                    $spans[] = ['text' => $entry['display'], 't_start' => $start, 't_end' => $prev];
                    $start = $t;
                }
                $prev = $t;
            }
            $spans[] = ['text' => $entry['display'], 't_start' => $start, 't_end' => $prev];
        }

        usort($spans, fn (array $a, array $b): int => $a['t_start'] <=> $b['t_start'] ?: $a['t_end'] <=> $b['t_end']);

        return $spans;
    }

    /**
     * The join: one row per interaction, carrying the accessibility label of what was clicked
     * (`on_screen_text`), the pixel-precise OCR line under the cursor (`ocr_at_click`), and the
     * words said around it.
     *
     * @param  array<int, list<OcrLine>>  $ocrLinesByFrame
     * @param  list<array{word: string, t_ms: int}>  $words
     * @return list<array<string, mixed>>
     */
    private function events(Pack $pack, array $ocrLinesByFrame, array $words, int $saidWindowMs, int $frameHeight): array
    {
        $rows = [];
        foreach ($pack->interactions() as $event) {
            $row = array_filter([
                't_ms' => $event->tMs,
                'type' => $event->type,
                'x' => $event->x,
                'y' => $event->y,
                'button' => $event->button,
                'app' => $event->app,
                'window' => $event->window,
            ], fn ($v): bool => $v !== null);

            if ($event->ax !== []) {
                $row['ax'] = array_filter([
                    'role' => $event->axRole(),
                    'label' => $event->axLabel(),
                ], fn ($v): bool => $v !== null);
            }

            if (($label = $event->axLabel()) !== null) {
                $row['on_screen_text'] = $label;
            }

            if ($event->x !== null && $event->y !== null) {
                $clicked = $this->lineUnderPoint($ocrLinesByFrame, $event->tMs, $event->x, $event->y, $frameHeight);
                if ($clicked !== null) {
                    $row['ocr_at_click'] = $clicked;
                }
            }

            $said = $this->saidAround($words, $event->tMs, $saidWindowMs);
            if ($said !== '') {
                $row['said'] = $said;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Cheap, pre-detected edit landmarks. v1 = what telemetry alone gives: a labelled click and an
     * app switch. (raised_voice / camera moments arrive with audio-prosody + MediaPipe in Phase 2.)
     *
     * @return list<array{t_ms: int, kind: string, label: string, score: float}>
     */
    private function moments(Pack $pack): array
    {
        $moments = [];
        foreach ($pack->interactions() as $event) {
            if ($event->type === 'click' && ($label = $event->axLabel()) !== null) {
                $moments[] = ['t_ms' => $event->tMs, 'kind' => 'click_on', 'label' => $label, 'score' => 0.9];
            } elseif ($event->type === 'app_focus' && $event->app !== null) {
                $moments[] = ['t_ms' => $event->tMs, 'kind' => 'app_switch', 'label' => $event->app, 'score' => 1.0];
            }
        }

        usort($moments, fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);

        return $moments;
    }

    /**
     * The OCR line under a point, taken from the frame AT that t_ms (interactions are always sampled,
     * so the exact frame usually exists) or the nearest frame otherwise. Returns null if no line box
     * covers the point.
     *
     * @param  array<int, list<OcrLine>>  $ocrLinesByFrame
     */
    private function lineUnderPoint(array $ocrLinesByFrame, int $tMs, int $x, int $y, int $frameHeight): ?string
    {
        $lines = $ocrLinesByFrame[$tMs] ?? $this->nearestFrameLines($ocrLinesByFrame, $tMs);
        if ($lines === null) {
            return null;
        }

        foreach ($this->visibleLines($lines, $frameHeight) as $line) {
            [$bx, $by, $bw, $bh] = $line['box'];
            if ($x >= $bx && $x <= $bx + $bw && $y >= $by && $y <= $by + $bh) {
                $text = trim($line['text']);

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }

    /**
     * @param  array<int, list<OcrLine>>  $ocrLinesByFrame
     * @return list<OcrLine>|null
     */
    private function nearestFrameLines(array $ocrLinesByFrame, int $tMs): ?array
    {
        $bestKey = null;
        $bestDist = PHP_INT_MAX;
        foreach (array_keys($ocrLinesByFrame) as $f) {
            $d = abs($f - $tMs);
            if ($d < $bestDist) {
                $bestDist = $d;
                $bestKey = $f;
            }
        }

        return $bestKey === null ? null : $ocrLinesByFrame[$bestKey];
    }

    /**
     * Drop top-of-frame OS chrome (the menu bar) and single-character noise.
     *
     * @param  list<OcrLine>  $lines
     * @return list<OcrLine>
     */
    private function visibleLines(array $lines, int $frameHeight): array
    {
        $cutoff = $frameHeight > 0 ? (int) round($frameHeight * self::TOP_CHROME_FRACTION) : 0;

        $out = [];
        foreach ($lines as $line) {
            if ($cutoff > 0 && $line['box'][1] < $cutoff) {
                continue;
            }
            if (mb_strlen(trim($line['text'])) <= 1) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Normalise a line to a dedup key: lowercase, drop everything but letters and digits. Folds the
     * punctuation/spacing/casing jitter between frames so the same line collapses to one span.
     */
    private function normalizeKey(string $text): string
    {
        return (string) preg_replace('/[^a-z0-9]+/u', '', mb_strtolower($text));
    }

    /**
     * Transcript words whose time falls within ±$windowMs of an event, joined into a phrase.
     *
     * @param  list<array{word: string, t_ms: int}>  $words
     */
    private function saidAround(array $words, int $tMs, int $windowMs): string
    {
        $near = array_filter($words, fn (array $w): bool => abs($w['t_ms'] - $tMs) <= $windowMs);

        return trim(implode(' ', array_column($near, 'word')));
    }
}
