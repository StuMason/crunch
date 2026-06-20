<?php

/** crunch v2 — Step 1, round 3: confirm the final working set for the two fixed slots. */

require __DIR__ . '/vendor/autoload.php';

use Codewithkyrian\Transformers\Transformers;
use Codewithkyrian\Transformers\PreTrainedTokenizers\AutoTokenizer;
use Codewithkyrian\Transformers\Models\Auto\AutoModelForSequenceClassification;
use function Codewithkyrian\Transformers\Pipelines\pipeline;

Transformers::setup()->setCacheDir(getenv('HF_CACHE') ?: '/data/models')->apply();

function ms(int $t): float { return round((hrtime(true) - $t) / 1e6, 1); }
function mem(): string {
    if (is_readable('/proc/self/status') && preg_match('/VmHWM:\s+(\d+) kB/', file_get_contents('/proc/self/status'), $m))
        return round((int) $m[1] / 1024) . ' MB';
    return round(memory_get_peak_usage(true) / 1048576) . ' MB';
}
function line(string $s): void { fwrite(STDOUT, $s . "\n"); }
function sigmoid(float $x): float { return 1 / (1 + exp(-$x)); }
function probe(string $name, callable $build, callable $run, int $iters = 3): void {
    line("\n=== $name ===");
    try {
        $t = hrtime(true); $obj = $build();
        line(sprintf('  LOAD : %s ms   (peak RSS %s)', ms($t), mem()));
        $times = []; $sample = null;
        for ($i = 0; $i < $iters; $i++) { $t = hrtime(true); $o = $run($obj); $times[] = ms($t); if ($i === 0) $sample = $o; }
        line('  WARM : ' . implode(' / ', array_map(fn($x) => $x . 'ms', $times)));
        line('  OUT  : ' . substr(str_replace("\n", ' ', json_encode($sample)), 0, 200));
        line('  RESULT: PASS');
    } catch (\Throwable $e) {
        line('  RESULT: FAIL — ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 240));
    }
}

line('PHP ' . PHP_VERSION . ' | arch ' . php_uname('m'));

// EMBED — EmbeddingGemma q8 (model_quantized.onnx + .onnx_data pre-fetched into cache).
probe('Embeddings (EmbeddingGemma-300m q8, ext-data pre-fetched)',
    fn() => pipeline('feature-extraction', 'onnx-community/embeddinggemma-300m-ONNX', quantized: false, modelFilename: 'model_quantized'),
    fn($p) => $p('The quick brown fox jumps over the lazy dog.', normalize: true, pooling: 'mean'));

// RERANK — BERT cross-encoder (ms-marco-MiniLM-L-6-v2), supported arch.
probe('Rerank (ms-marco-MiniLM-L-6-v2)',
    fn() => ['tok' => AutoTokenizer::fromPretrained('Xenova/ms-marco-MiniLM-L-6-v2'),
             'model' => AutoModelForSequenceClassification::fromPretrained('Xenova/ms-marco-MiniLM-L-6-v2')],
    function ($o) {
        $q = 'What is a healing peptide?';
        $score = function (string $p) use ($o, $q) {
            $in = $o['tok']->tokenize($q, textPair: $p, padding: true, truncation: true);
            $l = $o['model']($in)->logits->toArray();
            return round(sigmoid((float) (is_array($l[0]) ? $l[0][0] : $l[0])), 4);
        };
        return ['relevant: BPC-157 repairs tissue' => $score('BPC-157 is a peptide that repairs tissue'),
                'irrelevant: bake bread' => $score('How to bake sourdough bread at home')];
    });

line("\nDONE. Final peak RSS: " . mem());
