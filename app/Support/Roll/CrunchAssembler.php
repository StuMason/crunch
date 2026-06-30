<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\Pack;

/**
 * Assembles the lean `crunch.json` an editing agent reads — the join of
 * `event × on-screen-text × words-said` on the pack's shared clock.
 *
 * Design rules (from the roll-mac review):
 *  - It is an INDEX, not a data dump. Persistent on-screen text is deduped into time-spans
 *    ({text, t_start, t_end}), never one row per frame; cursor rows never appear; no embeddings.
 *  - "What was interacted with" prefers the OS accessibility label (`ax.label`) — a direct
 *    measurement of the clicked element — and only falls back to OCR. (Full-frame OCR gives no
 *    per-element boxes, so point-precise OCR fallback waits for box-level OCR; for now the
 *    surrounding on-screen-text is queryable via the `screen` spans around the event's t_ms.)
 *  - Only facts + cheap moments. No cut decisions — that stays with the agent.
 *
 * Pure: takes already-computed OCR text per frame and transcript words (on the shared clock),
 * returns the output array. No I/O, so the whole join is unit-testable without sidecars.
 */
class CrunchAssembler
{
    /**
     * @param  array<int, string>  $ocrByFrame  screen-clock t_ms => full-frame OCR text
     * @param  list<array{word: string, t_ms: int}>  $words  transcript words on the shared clock
     * @return array<string, mixed> the crunch.json structure
     */
    public function assemble(
        Pack $pack,
        string $packId,
        array $ocrByFrame,
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
                'words' => array_values($words),
            ],
            'screen' => $this->textSpans($ocrByFrame, $spanGapMs),
            'events' => $this->events($pack, $words, $saidWindowMs),
            'moments' => $this->moments($pack),
        ];
    }

    /**
     * Collapse per-frame OCR into deduped on-screen-text spans. Each distinct line of text
     * becomes one or more {text, t_start, t_end} spans — a line seen across consecutive frames
     * is one span; if it disappears for longer than $gapMs and returns, that's a new span.
     *
     * @param  array<int, string>  $ocrByFrame
     * @return list<array{text: string, t_start: int, t_end: int}>
     */
    private function textSpans(array $ocrByFrame, int $gapMs): array
    {
        ksort($ocrByFrame);

        /** @var array<string, array{display: string, times: list<int>}> $byLine */
        $byLine = [];
        foreach ($ocrByFrame as $tMs => $blob) {
            foreach ($this->lines($blob) as $line) {
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
     * The join: one row per interaction (cursor noise already excluded), carrying what was
     * clicked (ax.label first) and what was being said around it.
     *
     * @param  list<array{word: string, t_ms: int}>  $words
     * @return list<array<string, mixed>>
     */
    private function events(Pack $pack, array $words, int $saidWindowMs): array
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

            $said = $this->saidAround($words, $event->tMs, $saidWindowMs);
            if ($said !== '') {
                $row['said'] = $said;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Cheap, pre-detected edit landmarks the agent shouldn't have to scan for. v1 = what we can
     * derive from telemetry alone: a click on a labelled element, and an app switch. (raised_voice
     * / camera moments arrive with the audio-prosody + MediaPipe work in Phase 2.)
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
     * Transcript words whose time falls within ±$windowMs of an event, joined into a phrase.
     *
     * @param  list<array{word: string, t_ms: int}>  $words
     */
    private function saidAround(array $words, int $tMs, int $windowMs): string
    {
        $near = array_filter($words, fn (array $w): bool => abs($w['t_ms'] - $tMs) <= $windowMs);

        return trim(implode(' ', array_column($near, 'word')));
    }

    /**
     * Split an OCR blob into clean, de-duplicated lines worth indexing.
     *
     * @return list<string>
     */
    private function lines(string $blob): array
    {
        $lines = [];
        foreach (preg_split('/\R/', $blob) ?: [] as $line) {
            $line = trim((string) preg_replace('/\s+/', ' ', $line));
            if ($line !== '' && mb_strlen($line) > 1) {
                $lines[$line] = $line;   // dedupe repeated lines within one frame
            }
        }

        return array_values($lines);
    }
}
