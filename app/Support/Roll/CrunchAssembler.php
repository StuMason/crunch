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
 * @phpstan-type Moment array{t_ms: int, kind: string, label: string, score: float, source: string}
 */
class CrunchAssembler
{
    /**
     * Lines whose top edge sits within this fraction of the frame height are treated as the OS menu
     * bar / top chrome and dropped from the screen index.
     */
    private const TOP_CHROME_FRACTION = 0.03;

    /**
     * Spoken cues that mark an editorially-interesting beat, with a score for how strong a signal
     * each is. Matched on word boundaries against the transcript so an editor can jump to "the bit
     * that matters" the presenter flagged out loud.
     */
    private const CUE_PHRASES = [
        'pay attention' => 0.9,
        'the important thing' => 0.9,
        'the important part' => 0.9,
        'the whole point' => 0.85,
        'the best part' => 0.85,
        "here's the thing" => 0.85,
        'look at this' => 0.85,
        'check this out' => 0.85,
        'watch this' => 0.85,
        'the magic' => 0.8,
        'the point is' => 0.8,
        'this is where' => 0.8,
        "what's interesting" => 0.8,
        'crucially' => 0.8,
        'the trick' => 0.75,
        'the cool' => 0.75,
        "here's the" => 0.7,
        'the key' => 0.7,
        'notably' => 0.7,
        'this is the' => 0.65,
        'the real' => 0.6,
    ];

    /** Keep at most this many spoken-cue moments — an editorial shortlist, not every utterance. */
    private const MAX_CUE_MOMENTS = 25;

    public function __construct(private readonly Segmenter $segmenter = new Segmenter) {}

    /**
     * @param  array<int, list<OcrLine>>  $ocrLinesByFrame  screen-clock t_ms => OCR lines
     * @param  list<array{word: string, t_ms: int, t_end_ms?: int, confidence?: float}>  $words  transcript words on the shared clock
     * @param  list<Moment>  $prosodyMoments  emphasis/pause moments from {@see AudioProsody}
     * @param  list<array{word: string, t_ms: int, t_end_ms?: int, confidence?: float}>  $sysWords  system-audio transcript words on the shared clock
     * @param  list<array{t_ms: int, present: bool, score: float}>  $cameraSamples  on-camera presence samples
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
        array $prosodyMoments = [],
        array $sysWords = [],
        array $cameraSamples = [],
    ): array {
        $durationMs = (int) round($pack->manifest->durationMs);
        $cameraSpans = $this->cameraSpans($cameraSamples);
        $moments = $this->moments($pack, $words, $prosodyMoments, $cameraSpans);

        $out = [
            'pack_id' => $packId,
            'duration_ms' => $durationMs,
            'fps' => $pack->manifest->fps,
            'transcript' => [
                'text' => trim(implode(' ', array_column($words, 'word'))),
                'words' => $words,
            ],
            'screen' => $this->textSpans($ocrLinesByFrame, $spanGapMs, $frameHeight),
            'events' => $this->events($pack, $ocrLinesByFrame, $words, $saidWindowMs, $frameHeight),
            'moments' => $moments,
            'segments' => $this->segmenter->segment($durationMs, $pack->interactions(), $words, $moments),
        ];

        // Optional tracks — only surfaced when the take actually carries them, so a mic-only pack's
        // crunch.json stays exactly as before.
        if ($sysWords !== []) {
            $out['sys_transcript'] = [
                'text' => trim(implode(' ', array_column($sysWords, 'word'))),
                'words' => $sysWords,
            ];
        }
        if ($cameraSpans !== []) {
            $out['camera'] = $cameraSpans;
        }

        return $out;
    }

    /**
     * Collapse per-frame on-camera presence samples into `on_camera` time-spans: a maximal run of
     * consecutive present frames becomes one {t_start, t_end} span (a single absent sample between
     * two present ones ends the span). An index of when the presenter was on camera, not a per-frame
     * dump — the mirror of {@see textSpans()} for the screen.
     *
     * @param  list<array{t_ms: int, present: bool, score: float}>  $samples  sorted by t_ms
     * @return list<array{t_start: int, t_end: int}>
     */
    private function cameraSpans(array $samples): array
    {
        $spans = [];
        $start = null;
        $prev = null;
        foreach ($samples as $s) {
            if ($s['present']) {
                $start ??= $s['t_ms'];
                $prev = $s['t_ms'];
            } elseif ($start !== null) {
                $spans[] = ['t_start' => $start, 't_end' => (int) $prev];
                $start = null;
            }
        }
        if ($start !== null) {
            $spans[] = ['t_start' => $start, 't_end' => (int) $prev];
        }

        return $spans;
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
     * @param  list<array{word: string, t_ms: int, t_end_ms?: int, confidence?: float}>  $words
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
                'text' => $event->text,
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
     * Scored edit landmarks from every signal we have, merged onto one timeline so an editor can
     * rank or threshold them. Each carries a `source`:
     *  - `telemetry`: a labelled click, an app switch, or typed text (what the OS told us).
     *  - `transcript`: a spoken cue the presenter flagged out loud ("the important thing is…").
     *  - `audio`: vocal emphasis (loudness peaks) and pauses (cut points), from {@see AudioProsody}.
     *  - `camera`: the presenter coming (back) on camera, from the on-camera presence track.
     *
     * @param  list<array{word: string, t_ms: int, t_end_ms?: int, confidence?: float}>  $words
     * @param  list<Moment>  $prosodyMoments
     * @param  list<array{t_start: int, t_end: int}>  $cameraSpans
     * @return list<Moment>
     */
    private function moments(Pack $pack, array $words, array $prosodyMoments, array $cameraSpans = []): array
    {
        $moments = [];
        foreach ($pack->interactions() as $event) {
            if ($event->type === 'click' && ($label = $event->axLabel()) !== null) {
                $moments[] = ['t_ms' => $event->tMs, 'kind' => 'click_on', 'label' => $label, 'score' => 0.9, 'source' => 'telemetry'];
            } elseif ($event->type === 'app_focus' && $event->app !== null) {
                $moments[] = ['t_ms' => $event->tMs, 'kind' => 'app_switch', 'label' => $event->app, 'score' => 1.0, 'source' => 'telemetry'];
            } elseif ($event->type === 'text' && $event->text !== null && trim($event->text) !== '') {
                $moments[] = ['t_ms' => $event->tMs, 'kind' => 'type_text', 'label' => trim($event->text), 'score' => 0.8, 'source' => 'telemetry'];
            }
        }

        // The presenter coming (back) on camera is a cut point an editor cares about: one moment per
        // on-camera span onset.
        foreach ($cameraSpans as $span) {
            $moments[] = ['t_ms' => $span['t_start'], 'kind' => 'on_camera', 'label' => 'presenter on camera', 'score' => 0.6, 'source' => 'camera'];
        }

        $moments = array_merge($moments, $this->transcriptMoments($words), $prosodyMoments);

        usort($moments, fn (array $a, array $b): int => $a['t_ms'] <=> $b['t_ms']);

        return $moments;
    }

    /**
     * Spoken-cue moments: scan the transcript for the editorial cue phrases the presenter says out
     * loud and emit a scored moment at each (word-boundary matched, deduped to the strongest cue
     * within a beat, capped to a shortlist).
     *
     * @param  list<array{word: string, t_ms: int, t_end_ms?: int, confidence?: float}>  $words
     * @return list<Moment>
     */
    private function transcriptMoments(array $words): array
    {
        if ($words === []) {
            return [];
        }

        // Build the lowercased transcript with a char-offset => t_ms map for each word start.
        $text = '';
        /** @var array<int, int> $offsets */
        $offsets = [];
        foreach ($words as $w) {
            $offsets[strlen($text)] = $w['t_ms'];
            $text .= mb_strtolower(trim($w['word'])).' ';
        }

        $moments = [];
        foreach (self::CUE_PHRASES as $phrase => $score) {
            $from = 0;
            while (($pos = strpos($text, $phrase, $from)) !== false) {
                $from = $pos + strlen($phrase);
                $before = $pos === 0 || $text[$pos - 1] === ' ';
                $after = $from >= strlen($text) || $text[$from] === ' ';
                if ($before && $after) {
                    $moments[] = [
                        't_ms' => $this->tMsAtOffset($offsets, $pos),
                        'kind' => 'cue_phrase',
                        'label' => $phrase,
                        'score' => $score,
                        'source' => 'transcript',
                    ];
                }
            }
        }

        return $this->topCueMoments($moments);
    }

    /**
     * Keep the strongest cue per beat (collapse cues landing within a second of each other) and cap
     * the shortlist.
     *
     * @param  list<Moment>  $moments
     * @return list<Moment>
     */
    private function topCueMoments(array $moments): array
    {
        usort($moments, fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['t_ms'] <=> $b['t_ms']);

        $kept = [];
        foreach ($moments as $m) {
            foreach ($kept as $k) {
                if (abs($k['t_ms'] - $m['t_ms']) <= 1000) {
                    continue 2;
                }
            }
            $kept[] = $m;
            if (count($kept) >= self::MAX_CUE_MOMENTS) {
                break;
            }
        }

        return $kept;
    }

    /**
     * The t_ms of the word containing a char offset — the latest word-start at or before it.
     *
     * @param  array<int, int>  $offsets  char offset => t_ms (ascending insertion order)
     */
    private function tMsAtOffset(array $offsets, int $pos): int
    {
        $best = 0;
        foreach ($offsets as $offset => $tMs) {
            if ($offset > $pos) {
                break;
            }
            $best = $tMs;
        }

        return $best;
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
     * @param  list<array{word: string, t_ms: int, t_end_ms?: int, confidence?: float}>  $words
     */
    private function saidAround(array $words, int $tMs, int $windowMs): string
    {
        $near = array_filter($words, fn (array $w): bool => abs($w['t_ms'] - $tMs) <= $windowMs);

        return trim(implode(' ', array_column($near, 'word')));
    }
}
