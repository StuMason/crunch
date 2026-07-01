<?php

declare(strict_types=1);

use App\DataTransferObjects\Roll\PackEvent;
use App\Support\Roll\Segmenter;

/**
 * @param  list<array{t_ms:int,kind:string,score:float}>  $partial
 * @return list<array{t_ms:int,kind:string,label:string,score:float,source:string}>
 */
function moments(array $partial): array
{
    return array_map(fn ($m) => [
        't_ms' => $m['t_ms'], 'kind' => $m['kind'], 'label' => $m['kind'],
        'score' => $m['score'], 'source' => 'test',
    ], $partial);
}

function ev(string $type, int $tMs, ?string $app, ?string $window): PackEvent
{
    return new PackEvent($type, $tMs, null, null, null, $app, $window, [], []);
}

it('cuts the timeline at app switches and labels each beat extractively', function () {
    $events = [
        ev('app_focus', 0, 'Arc', 'notrobo.shop'),
        ev('scroll', 5000, 'Arc', 'notrobo.shop'),
        ev('app_focus', 20000, 'Claude', 'Claude'),
    ];
    $words = [
        ['word' => 'The', 't_ms' => 1000], ['word' => 'shop', 't_ms' => 1500],
        ['word' => 'handles', 't_ms' => 2000], ['word' => 'payments.', 't_ms' => 2500],
        ['word' => 'Now', 't_ms' => 21000], ['word' => 'Claude', 't_ms' => 21500],
        ['word' => 'writes', 't_ms' => 22000], ['word' => 'the', 't_ms' => 22500],
        ['word' => 'code.', 't_ms' => 23000],
    ];
    $ms = moments([
        ['t_ms' => 0, 'kind' => 'app_switch', 'score' => 1.0],
        ['t_ms' => 20000, 'kind' => 'app_switch', 'score' => 1.0],
    ]);

    $segments = (new Segmenter)->segment(40000, $events, $words, $ms);

    expect($segments)->toHaveCount(2)
        ->and($segments[0])->toMatchArray(['t_start' => 0, 't_end' => 20000, 'title' => 'notrobo.shop'])
        ->and($segments[0]['apps'])->toBe(['Arc'])
        ->and($segments[0]['keywords'])->toContain('shop')
        ->and($segments[1])->toMatchArray(['t_start' => 20000, 't_end' => 40000, 'title' => 'Claude'])
        ->and($segments[1]['keywords'])->toContain('code');
});

it('splits a long single-app stretch on a strong pause', function () {
    $events = [ev('app_focus', 0, 'Arc', 'page')];
    $words = [['word' => 'one', 't_ms' => 1000], ['word' => 'two', 't_ms' => 30000]];
    $ms = moments([['t_ms' => 15000, 'kind' => 'pause', 'score' => 0.7]]);

    $segments = (new Segmenter)->segment(40000, $events, $words, $ms);

    expect($segments)->toHaveCount(2)
        ->and($segments[0]['t_end'])->toBe(15000);
});

it('keeps one segment when nothing justifies a cut', function () {
    $segments = (new Segmenter)->segment(40000, [ev('app_focus', 0, 'Arc', 'page')], [], moments([
        ['t_ms' => 2000, 'kind' => 'app_switch', 'score' => 1.0],   // too close to start — dropped
    ]));

    expect($segments)->toHaveCount(1)
        ->and($segments[0])->toMatchArray(['t_start' => 0, 't_end' => 40000]);
});

it('picks the keyword-densest sentence as the summary', function () {
    $events = [ev('app_focus', 0, 'Arc', 'page')];
    $words = [];
    foreach (explode(' ', 'Hello there. The payment flow uses x402 for the checkout payment. Bye.') as $i => $w) {
        $words[] = ['word' => $w, 't_ms' => 1000 + $i * 100];
    }

    $summary = (new Segmenter)->segment(40000, $events, $words, [])[0]['summary'];

    expect($summary)->toBe('The payment flow uses x402 for the checkout payment.');
});
