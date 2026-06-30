<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\Pack;

/**
 * Assembles the lean `crunch.json` an editing agent reads — the join of
 * `event × on-screen-text × words-said` on the pack's shared clock.
 *
 * Works from word-level OCR (each word with a confidence and a pixel box), which is what makes
 * the screen side trustworthy:
 *  - `screen` spans are built from confidence-filtered reading-order lines, deduped into
 *    {text, t_start, t_end} time-spans — an index of what was on screen when, not a per-frame dump.
 *  - `ocr_at_click` resolves a click to the exact line under the cursor. roll now emits click
 *    coords in screen.mp4 pixel space (the same space as the OCR boxes), so the lookup is direct.
 *  - `on_screen_text` stays the OS accessibility label (stable for downstream bindings); the new
 *    pixel-precise text rides alongside it as `ocr_at_click`.
 *
 * Pure: takes already-computed OCR words per frame + transcript words (on the shared clock),
 * returns the output array. No I/O, so the whole join is unit-testable without sidecars.
 *
 * @phpstan-type OcrWord array{text: string, conf: float, box: array<int, int>, line: array<int, int>}
 */
class CrunchAssembler
{
    /**
     * @param  array<int, list<OcrWord>>  $ocrWordsByFrame  screen-clock t_ms => confidence-filtered OCR words
     * @param  list<array{word: string, t_ms: int}>  $words  transcript words on the shared clock
     * @return array<string, mixed> the crunch.json structure
     */
    public function assemble(
        Pack $pack,
        string $packId,
        array $ocrWordsByFrame,
        array $words,
        int $saidWindowMs = 2500,
        int $spanGapMs = 2500,
    ): array {
        return [
            'pack_id' => $packId,
            'duration_ms' => (int) round($pack->manifest->durationMs),
            'fps' => $pack->manifest->fps,
            'transcript' => [
                'text' => trim(implode(' ', array_column($words, 'word'))),
                'words' => $words,
            ],
            'screen' => $this->textSpans($ocrWordsByFrame, $spanGapMs),
            'events' => $this->events($pack, $ocrWordsByFrame, $words, $saidWindowMs),
            'moments' => $this->moments($pack),
        ];
    }

    /**
     * Collapse per-frame OCR lines into deduped on-screen-text spans. Each distinct line becomes
     * one or more {text, t_start, t_end} spans — seen across consecutive frames = one span; gone
     * for longer than $gapMs then back = a new span.
     *
     * @param  array<int, list<OcrWord>>  $ocrWordsByFrame
     * @return list<array{text: string, t_start: int, t_end: int}>
     */
    private function textSpans(array $ocrWordsByFrame, int $gapMs): array
    {
        ksort($ocrWordsByFrame);

        /** @var array<string, array{display: string, times: list<int>}> $byLine */
        $byLine = [];
        foreach ($ocrWordsByFrame as $tMs => $frameWords) {
            foreach ($this->frameLines($frameWords) as $line) {
                $key = mb_strtolower($line);
                $byLine[$key] ??= ['display' => $line, 'times' => []];
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
     * @param  array<int, list<OcrWord>>  $ocrWordsByFrame
     * @param  list<array{word: string, t_ms: int}>  $words
     * @return list<array<string, mixed>>
     */
    private function events(Pack $pack, array $ocrWordsByFrame, array $words, int $saidWindowMs): array
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
                $clicked = $this->lineUnderPoint($ocrWordsByFrame, $event->tMs, $event->x, $event->y);
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
     * The reading-order OCR line under a point, taken from the frame AT that t_ms (interactions are
     * always sampled, so the exact frame usually exists) or the nearest frame otherwise. Returns null
     * if no word box covers the point.
     *
     * @param  array<int, list<OcrWord>>  $ocrWordsByFrame
     */
    private function lineUnderPoint(array $ocrWordsByFrame, int $tMs, int $x, int $y): ?string
    {
        $frameWords = $ocrWordsByFrame[$tMs] ?? $this->nearestFrameWords($ocrWordsByFrame, $tMs);
        if ($frameWords === null) {
            return null;
        }

        $hitLine = null;
        foreach ($frameWords as $w) {
            [$bx, $by, $bw, $bh] = $w['box'];
            if ($x >= $bx && $x <= $bx + $bw && $y >= $by && $y <= $by + $bh) {
                $hitLine = $w['line'];
                break;
            }
        }

        if ($hitLine === null) {
            return null;
        }

        $line = array_values(array_filter($frameWords, fn (array $w): bool => $w['line'] === $hitLine));
        usort($line, fn (array $a, array $b): int => $a['box'][0] <=> $b['box'][0]);
        $text = trim(implode(' ', array_column($line, 'text')));

        return $text !== '' ? $text : null;
    }

    /**
     * @param  array<int, list<OcrWord>>  $ocrWordsByFrame
     * @return list<OcrWord>|null
     */
    private function nearestFrameWords(array $ocrWordsByFrame, int $tMs): ?array
    {
        $bestKey = null;
        $bestDist = PHP_INT_MAX;
        foreach (array_keys($ocrWordsByFrame) as $f) {
            $d = abs($f - $tMs);
            if ($d < $bestDist) {
                $bestDist = $d;
                $bestKey = $f;
            }
        }

        return $bestKey === null ? null : $ocrWordsByFrame[$bestKey];
    }

    /**
     * Group one frame's words into reading-order line strings (top-to-bottom, left-to-right).
     *
     * @param  list<OcrWord>  $frameWords
     * @return list<string>
     */
    private function frameLines(array $frameWords): array
    {
        /** @var array<string, list<OcrWord>> $lines */
        $lines = [];
        foreach ($frameWords as $w) {
            $lines[implode(':', $w['line'])][] = $w;
        }

        uasort($lines, fn (array $a, array $b): int => $this->topEdge($a) <=> $this->topEdge($b));

        $out = [];
        foreach ($lines as $lineWords) {
            usort($lineWords, fn (array $a, array $b): int => $a['box'][0] <=> $b['box'][0]);
            $line = trim(implode(' ', array_column($lineWords, 'text')));
            if (mb_strlen($line) > 1) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * @param  list<OcrWord>  $lineWords
     */
    private function topEdge(array $lineWords): int
    {
        $tops = array_map(fn (array $w): int => $w['box'][1], $lineWords);

        return $tops === [] ? 0 : min($tops);
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
