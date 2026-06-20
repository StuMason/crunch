<?php

/**
 * crunch v2 spike — does transformers-php run, and run fast enough, on arm64 Linux?
 *
 * For each capability we measure the two numbers that decide the architecture:
 *   - LOAD ms  : building the pipeline (model into memory). This is what Octane
 *                amortises by keeping the process warm — paid once, not per request.
 *   - WARM ms  : steady-state inference latency once loaded (what a real request costs).
 *                We loop a few times; the first call can include lazy init.
 * Plus peak RSS after each model loads, to confirm everything fits the box's RAM.
 *
 * A long-lived CLI process == Octane's warm-model path, so this is representative
 * without needing Octane itself for the gate.
 */

require __DIR__ . '/vendor/autoload.php';

use Codewithkyrian\Transformers\Transformers;
use function Codewithkyrian\Transformers\Pipelines\pipeline;

Transformers::setup()
    ->setCacheDir(getenv('HF_CACHE') ?: '/data/models')
    ->apply();

function ms(int $startNs): float { return round((hrtime(true) - $startNs) / 1e6, 1); }
// True peak RSS incl. ONNX/FFI native allocs (PHP's memory_get_peak_usage misses those).
function mem(): string {
    if (is_readable('/proc/self/status') && preg_match('/VmHWM:\s+(\d+) kB/', file_get_contents('/proc/self/status'), $m)) {
        return round((int) $m[1] / 1024) . ' MB';
    }
    return round(memory_get_peak_usage(true) / 1048576) . ' MB';
}
function line(string $s): void { fwrite(STDOUT, $s . "\n"); }

/** Run one capability in isolation: build pipeline, time load, loop inference. */
function probe(string $name, string $task, string $model, callable $run, int $iters = 4): void
{
    line("\n=== $name  ($task / $model) ===");
    try {
        $t = hrtime(true);
        $pipe = pipeline($task, $model);
        line(sprintf('  LOAD : %s ms   (peak RSS now %s)', ms($t), mem()));

        $times = [];
        $sample = null;
        for ($i = 0; $i < $iters; $i++) {
            $t = hrtime(true);
            $out = $run($pipe);
            $times[] = ms($t);
            if ($i === 0) $sample = $out;
        }
        line('  WARM : ' . implode(' / ', array_map(fn($x) => $x . 'ms', $times))
            . sprintf('   (median ~%sms)', $times[intdiv(count($times), 2)]));
        line('  OUT  : ' . substr(str_replace("\n", ' ', json_encode($sample)), 0, 180));
        line("  RESULT: PASS");
    } catch (\Throwable $e) {
        line('  RESULT: FAIL — ' . get_class($e) . ': ' . $e->getMessage());
    }
}

line('PHP ' . PHP_VERSION . ' | FFI ' . (extension_loaded('ffi') ? 'on' : 'OFF')
    . ' | arch ' . php_uname('m'));

$only = $argv[1] ?? 'all';
$want = fn(string $k) => $only === 'all' || $only === $k;

// 1) Embeddings — apples-to-apples with the live TEI bge-small.
if ($want('embed')) {
    probe('Embeddings', 'feature-extraction', 'Xenova/bge-small-en-v1.5',
        fn($p) => $p('The quick brown fox jumps over the lazy dog.', normalize: true, pooling: 'mean'));
}

// 2) Image captioning — the thing TEI fundamentally cannot do (Stu's non-negotiable).
if ($want('caption')) {
    probe('Captioning', 'image-to-text', 'Xenova/vit-gpt2-image-captioning',
        fn($p) => $p(__DIR__ . '/samples/image.jpg'));
}

// 3) Whisper ASR — does speech-to-text run in-process at all.
if ($want('whisper')) {
    probe('Whisper ASR', 'automatic-speech-recognition', 'Xenova/whisper-tiny.en',
        fn($p) => $p(__DIR__ . '/samples/audio.wav'));
}

// 4) Zero-shot image classification (CLIP) — bonus, the image-embed sibling.
if ($want('clip')) {
    probe('CLIP zero-shot', 'zero-shot-image-classification', 'Xenova/clip-vit-base-patch32',
        fn($p) => $p(__DIR__ . '/samples/image.jpg', ['a cat', 'a dog', 'a beach', 'a city street']));
}

line("\nDONE. Final peak RSS: " . mem());
