<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\PackEvent;

/**
 * Cuts the flat timeline into coherent beats — the story outline an editor actually thinks in
 * ("the site walkthrough", "over to Claude", "the payment flow"). Boundaries come from the
 * signals we already detect: an app switch is a hard scene change, a long pause is a soft one.
 * Each segment is then labelled *extractively* — no generative model, so it's CPU-only and fast:
 *  - title  = the dominant window the segment lived in (falls back to the app, then a keyword)
 *  - keywords = the most distinctive words spoken in the segment (frequency, stopwords removed)
 *  - summary = the spoken sentence densest in those keywords (the "what this beat is about" line)
 *
 * Pure: takes already-computed events / transcript / moments, returns the segments. Unit-testable
 * without any sidecar.
 *
 * @phpstan-type Moment array{t_ms: int, kind: string, label: string, score: float, source: string}
 * @phpstan-type Segment array{t_start: int, t_end: int, apps: list<string>, title: string, keywords: list<string>, summary: string, n_moments: int}
 */
class Segmenter
{
    /** Never emit a segment shorter than this — keeps the outline an outline, not a firehose. */
    private const MIN_SEGMENT_MS = 8000;

    /** A pause moment at least this strong (≈1.5s) can split a segment within a single app. */
    private const PAUSE_BOUNDARY_SCORE = 0.5;

    private const MAX_KEYWORDS = 6;

    /** @var list<string> */
    private const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'so', 'if', 'then', 'that', 'this', 'these', 'those',
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am', 'to', 'of', 'in', 'on', 'at', 'for',
        'with', 'as', 'by', 'from', 'it', 'its', 'i', 'you', 'we', 'they', 'he', 'she', 'me', 'my',
        'your', 'our', 'their', 'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'can',
        'could', 'should', 'just', 'like', 'really', 'actually', 'gonna', 'wanna', 'kind', 'sort',
        'here', 'there', 'what', 'which', 'who', 'when', 'where', 'how', 'not', 'no', 'yes', 'okay',
        'ok', 'right', 'now', 'thing', 'things', 'get', 'got', 'going', 'go', 'one', 'up', 'out',
        'about', 'into', 'because', 'well', 'very', 'much', 'more', 'some', 'any', 'all', 'also',
        'than', 'them', 'too', 'were', 'thats', 'theres', 'im', 'were', 'dont', 'cant', 'lets',
    ];

    /**
     * @param  list<PackEvent>  $events
     * @param  list<array{word: string, t_ms: int}>  $words
     * @param  list<Moment>  $moments
     * @return list<Segment>
     */
    public function segment(int $durationMs, array $events, array $words, array $moments): array
    {
        $edges = $this->edges($durationMs, $moments);

        $segments = [];
        for ($i = 0; $i < count($edges) - 1; $i++) {
            $start = $edges[$i];
            $end = $edges[$i + 1];

            $segEvents = array_values(array_filter($events, fn (PackEvent $e): bool => $e->tMs >= $start && $e->tMs < $end));
            $segWords = array_values(array_filter($words, fn (array $w): bool => $w['t_ms'] >= $start && $w['t_ms'] < $end));
            $keywords = $this->keywords($segWords);

            $segments[] = [
                't_start' => $start,
                't_end' => $end,
                'apps' => $this->appsIn($segEvents),
                'title' => $this->title($segEvents, $keywords),
                'keywords' => $keywords,
                'summary' => $this->summarySentence($segWords, $keywords),
                'n_moments' => count(array_filter($moments, fn (array $m): bool => $m['t_ms'] >= $start && $m['t_ms'] < $end)),
            ];
        }

        return $segments;
    }

    /**
     * Segment edges: 0, each boundary (app switch, or a long-enough pause), and the duration —
     * with boundaries dropped when they'd make either neighbouring segment shorter than the floor.
     *
     * @param  list<Moment>  $moments
     * @return list<int>
     */
    private function edges(int $durationMs, array $moments): array
    {
        $candidates = [];
        foreach ($moments as $m) {
            if ($m['kind'] === 'app_switch' || ($m['kind'] === 'pause' && $m['score'] >= self::PAUSE_BOUNDARY_SCORE)) {
                if ($m['t_ms'] > 0 && $m['t_ms'] < $durationMs) {
                    $candidates[] = $m['t_ms'];
                }
            }
        }
        $candidates = array_values(array_unique($candidates));
        sort($candidates);

        $edges = [0];
        foreach ($candidates as $b) {
            if ($b - $edges[count($edges) - 1] >= self::MIN_SEGMENT_MS && $durationMs - $b >= self::MIN_SEGMENT_MS) {
                $edges[] = $b;
            }
        }
        $edges[] = $durationMs;

        return $edges;
    }

    /**
     * Distinct apps active in the segment, in order of first appearance.
     *
     * @param  list<PackEvent>  $events
     * @return list<string>
     */
    private function appsIn(array $events): array
    {
        $apps = [];
        foreach ($events as $e) {
            if ($e->app !== null && ! in_array($e->app, $apps, true)) {
                $apps[] = $e->app;
            }
        }

        return $apps;
    }

    /**
     * Title = the window the segment mostly lived in; failing that the dominant app, then a keyword.
     *
     * @param  list<PackEvent>  $events
     * @param  list<string>  $keywords
     */
    private function title(array $events, array $keywords): string
    {
        $windows = [];
        foreach ($events as $e) {
            if ($e->window !== null && $e->window !== '') {
                $windows[$e->window] = ($windows[$e->window] ?? 0) + 1;
            }
        }
        if ($windows !== []) {
            arsort($windows);

            return (string) array_key_first($windows);
        }

        return $this->appsIn($events)[0] ?? ($keywords[0] ?? 'segment');
    }

    /**
     * The most distinctive spoken words in the segment: frequency-ranked, stopwords and short
     * tokens removed.
     *
     * @param  list<array{word: string, t_ms: int}>  $words
     * @return list<string>
     */
    private function keywords(array $words): array
    {
        $counts = [];
        foreach ($words as $w) {
            $token = preg_replace('/[^a-z0-9]/', '', mb_strtolower($w['word']));
            if ($token === null || mb_strlen($token) < 3 || in_array($token, self::STOPWORDS, true)) {
                continue;
            }
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        arsort($counts);

        return array_slice(array_keys($counts), 0, self::MAX_KEYWORDS);
    }

    /**
     * The spoken sentence densest in the segment's keywords — the line that best captures the beat.
     *
     * @param  list<array{word: string, t_ms: int}>  $words
     * @param  list<string>  $keywords
     */
    private function summarySentence(array $words, array $keywords): string
    {
        $text = trim(implode(' ', array_column($words, 'word')));
        if ($text === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/', $text) ?: [$text];
        $keys = array_flip($keywords);

        $best = '';
        $bestScore = -1;
        foreach ($sentences as $sentence) {
            $score = 0;
            foreach (preg_split('/\s+/', mb_strtolower($sentence)) ?: [] as $tok) {
                $tok = preg_replace('/[^a-z0-9]/', '', $tok);
                if ($tok !== null && isset($keys[$tok])) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = trim($sentence);
            }
        }

        return $best;
    }
}
