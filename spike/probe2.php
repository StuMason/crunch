<?php

/**
 * crunch v2 — Step 1 port verification for the BEST-IN-CLASS model set.
 * All loaded with quantized:false (fp32 model.onnx) to isolate "does the architecture
 * load + run" from quant-file-naming. fp32 latency is a conservative ceiling; q8 is faster.
 */

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

function probe(string $name, string $model, callable $build, callable $run, int $iters = 3): void {
    line("\n=== $name  [$model] ===");
    try {
        $t = hrtime(true);
        $obj = $build($model);
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

line('PHP ' . PHP_VERSION . ' | arch ' . php_uname('m') . ' | all models quantized:false (fp32)');
$IMG = __DIR__ . '/samples/image.jpg';
$WAV = __DIR__ . '/samples/audio.wav';
$only = $argv[1] ?? 'all';
$want = fn($k) => $only === 'all' || $only === $k;

// 1) Embeddings — EmbeddingGemma (gemma3 arch confirmed supported).
if ($want('embed')) probe('Embeddings (EmbeddingGemma-300m)', 'onnx-community/embeddinggemma-300m-ONNX',
    fn($m) => pipeline('feature-extraction', $m, quantized: false),
    fn($p) => $p('The quick brown fox jumps over the lazy dog.', normalize: true, pooling: 'mean'));

// 2) Rerank — cross-encoder via lower-level tokenizer+model (no rerank pipeline exists).
if ($want('rerank')) probe('Rerank (bge-reranker-v2-m3)', 'onnx-community/bge-reranker-v2-m3-ONNX',
    function ($m) {
        return ['tok' => AutoTokenizer::fromPretrained($m), 'model' => AutoModelForSequenceClassification::fromPretrained($m, false)];
    },
    function ($o) {
        $q = 'What is a healing peptide?';
        $score = function (string $passage) use ($o, $q) {
            $inputs = $o['tok']->tokenize($q, textPair: $passage, padding: true, truncation: true);
            $logits = $o['model']($inputs)->logits->toArray();
            return sigmoid((float) (is_array($logits[0]) ? $logits[0][0] : $logits[0]));
        };
        return ['relevant: BPC-157 repairs tissue' => round($score('BPC-157 repairs tissue'), 4),
                'irrelevant: how to bake bread'   => round($score('How to bake sourdough bread'), 4)];
    });

// 3) Moderation — KoalaAI multi-category (replaces hate-only).
if ($want('moderate')) probe('Moderation (KoalaAI/Text-Moderation)', 'KoalaAI/Text-Moderation',
    fn($m) => pipeline('text-classification', $m, quantized: false),
    fn($p) => $p('I will find you and hurt you, you worthless idiot.', topK: null));

// 4) Captioning — Florence-2 (florence2 config recognised; may need task prompt).
if ($want('caption')) probe('Captioning (Florence-2-base)', 'onnx-community/Florence-2-base',
    fn($m) => pipeline('image-to-text', $m, quantized: false),
    fn($p) => $p($IMG), 2);

// 5) Image classification — SigLIP (beats CLIP at zero-shot).
if ($want('clip')) probe('Zero-shot image (SigLIP-base)', 'Xenova/siglip-base-patch16-224',
    fn($m) => pipeline('zero-shot-image-classification', $m, quantized: false),
    fn($p) => $p($IMG, ['a cat', 'a dog', 'a beach', 'a city street']));

// 6) ASR — distil-whisper small.en (async lane).
if ($want('whisper')) probe('ASR (distil-small.en)', 'onnx-community/distil-small.en',
    fn($m) => pipeline('automatic-speech-recognition', $m, quantized: false),
    fn($p) => $p($WAV), 2);

line("\nDONE. Final peak RSS: " . mem());
