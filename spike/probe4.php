<?php

/** crunch v2 — Step 1, final: lock the embedder. Qwen3-Embedding (qwen3 arch, single-file q8)
 *  is the best-in-class shot; bge-base is the certain BERT fallback. */

require __DIR__ . '/vendor/autoload.php';

use Codewithkyrian\Transformers\Transformers;
use function Codewithkyrian\Transformers\Pipelines\pipeline;

Transformers::setup()->setCacheDir(getenv('HF_CACHE') ?: '/data/models')->apply();

function ms(int $t): float { return round((hrtime(true) - $t) / 1e6, 1); }
function mem(): string {
    if (is_readable('/proc/self/status') && preg_match('/VmHWM:\s+(\d+) kB/', file_get_contents('/proc/self/status'), $m))
        return round((int) $m[1] / 1024) . ' MB';
    return round(memory_get_peak_usage(true) / 1048576) . ' MB';
}
function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function probe(string $name, callable $build, callable $run, int $iters = 3): void {
    line("\n=== $name ===");
    try {
        $t = hrtime(true); $obj = $build();
        line(sprintf('  LOAD : %s ms   (peak RSS %s)', ms($t), mem()));
        $times = []; $dim = null;
        for ($i = 0; $i < $iters; $i++) { $t = hrtime(true); $o = $run($obj); $times[] = ms($t); if ($i === 0) $dim = is_array($o) ? count($o[0] ?? $o) : null; }
        line('  WARM : ' . implode(' / ', array_map(fn($x) => $x . 'ms', $times)) . "   dim=$dim");
        line('  RESULT: PASS');
    } catch (\Throwable $e) {
        line('  RESULT: FAIL — ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 220));
    }
}

line('PHP ' . PHP_VERSION . ' | arch ' . php_uname('m'));

probe('Embeddings (Qwen3-Embedding-0.6B q8, single-file)',
    fn() => pipeline('feature-extraction', 'onnx-community/Qwen3-Embedding-0.6B-ONNX', quantized: false, modelFilename: 'model_quantized'),
    fn($p) => $p('The quick brown fox jumps over the lazy dog.', normalize: true, pooling: 'mean'));

probe('Embeddings (bge-base-en-v1.5 — BERT fallback)',
    fn() => pipeline('feature-extraction', 'Xenova/bge-base-en-v1.5'),
    fn($p) => $p('The quick brown fox jumps over the lazy dog.', normalize: true, pooling: 'mean'));

line("\nDONE. Final peak RSS: " . mem());
