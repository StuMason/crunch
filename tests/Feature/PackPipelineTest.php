<?php

declare(strict_types=1);

use App\Inference\OcrClient;
use App\Support\Roll\FrameExtractor;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * The pack pipeline's parallel legs: per-frame OCR fanned out in bounded Http::pool waves
 * (OcrClient::linesMany) and frame extraction fanned out in bounded Process::pool waves
 * (FrameExtractor). Both are per-frame best-effort — a failed frame is omitted, never fatal.
 */
function tempFrameDir(): string
{
    $dir = sys_get_temp_dir().'/crunch-pack-test-'.uniqid();
    mkdir($dir, 0775, true);

    return $dir;
}

it('line-OCRs many frames concurrently, keyed by t_ms, omitting failed frames', function () {
    config(['crunch.ocr.url' => 'http://ocr:9000']);

    $dir = tempFrameDir();
    foreach ([0, 1000, 2000] as $t) {
        file_put_contents("{$dir}/frame_{$t}.png", 'png-bytes');
    }

    Http::fake(function ($request) {
        $filename = collect($request->data())->firstWhere('name', 'image')['filename'] ?? '';

        // Frame 1000 fails server-side — it must be omitted, not sink the batch.
        if ($filename === 'frame_1000.png') {
            return Http::response(['detail' => 'tesseract exploded'], 500);
        }

        return Http::response([
            'lines' => [['text' => "line from {$filename}", 'conf' => 91.5, 'box' => [10, 20, 300, 24]]],
            'text' => "line from {$filename}",
            'image_height' => 1440,
        ]);
    });

    $progress = [];
    $results = app(OcrClient::class)->linesMany(
        [0 => "{$dir}/frame_0.png", 1000 => "{$dir}/frame_1000.png", 2000 => "{$dir}/frame_2000.png"],
        concurrency: 2,
        onProgress: function (int $done, int $total) use (&$progress): void {
            $progress[] = [$done, $total];
        },
    );

    expect(array_keys($results))->toBe([0, 2000]);
    expect($results[0]['lines'][0]['text'])->toBe('line from frame_0.png');
    expect($results[0]['image_height'])->toBe(1440);
    expect($results[2000]['text'])->toBe('line from frame_2000.png');

    // Two waves at concurrency 2 (2 + 1), each reported as it completes.
    expect($progress)->toBe([[2, 3], [3, 3]]);
    Http::assertSentCount(3);
});

it('skips an unreadable frame file without sending a request for it', function () {
    config(['crunch.ocr.url' => 'http://ocr:9000']);

    $dir = tempFrameDir();
    file_put_contents("{$dir}/frame_0.png", 'png-bytes');

    Http::fake([
        '*' => Http::response(['lines' => [], 'text' => '', 'image_height' => 900]),
    ]);

    $results = app(OcrClient::class)->linesMany([
        0 => "{$dir}/frame_0.png",
        500 => "{$dir}/does-not-exist.png",
    ]);

    expect(array_keys($results))->toBe([0]);
    Http::assertSentCount(1);
});

it('extracts frames in parallel waves and returns only the frames ffmpeg produced', function () {
    Process::fake();

    $dir = tempFrameDir();
    // Simulate ffmpeg having written two of the three requested frames (2000 produced nothing).
    file_put_contents("{$dir}/frame_0.png", 'png-bytes');
    file_put_contents("{$dir}/frame_1000.png", 'png-bytes');

    $frames = (new FrameExtractor)->extractFrom(
        '/take/screen.mp4',
        [0, 1000, 2000],
        fn (int $tMs): float => $tMs / 1000,
        $dir,
        concurrency: 2,
    );

    expect($frames)->toBe([0 => "{$dir}/frame_0.png", 1000 => "{$dir}/frame_1000.png"]);

    // One ffmpeg per requested timestamp, each seeking its own t_ms.
    foreach ([0.0, 1.0, 2.0] as $seconds) {
        Process::assertRan(fn ($process): bool => in_array(sprintf('%.3f', $seconds), $process->command, true));
    }
});
