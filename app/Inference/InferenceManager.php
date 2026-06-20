<?php

declare(strict_types=1);

namespace App\Inference;

use Codewithkyrian\Transformers\Models\Auto\AutoModelForSequenceClassification;
use Codewithkyrian\Transformers\PreTrainedTokenizers\AutoTokenizer;
use Codewithkyrian\Transformers\Transformers;
use InvalidArgumentException;

use function Codewithkyrian\Transformers\Pipelines\pipeline;

/**
 * Builds and holds warm inference engines (one per capability).
 *
 * Registered as a container singleton so that under Octane the loaded models
 * persist in memory across requests — this is what makes synchronous inference
 * fast (the per-request model reload is what makes naive PHP-FPM slow).
 *
 * Holds NO request-specific state: engines are stateless, so a long-lived
 * singleton is safe under Octane.
 */
class InferenceManager
{
    /** @var array<string, mixed> Lazily built engines, keyed by capability. */
    private array $engines = [];

    private bool $booted = false;

    /**
     * Return the warm engine for a capability, building it on first use.
     *
     * For `pipeline` capabilities this is an invokable transformers-php Pipeline.
     * For `cross-encoder` (rerank) it is a ['tokenizer' => ..., 'model' => ...] pair,
     * since transformers-php has no rerank pipeline.
     */
    public function engine(string $capability): mixed
    {
        return $this->engines[$capability] ??= $this->build($capability);
    }

    /**
     * @return array{kind: string, ...} The resolved config for a capability.
     */
    public function config(string $capability): array
    {
        $config = config("crunch.models.$capability");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown crunch capability: {$capability}");
        }

        return $config;
    }

    private function build(string $capability): mixed
    {
        $this->boot();

        $config = $this->config($capability);

        return match ($config['kind']) {
            'pipeline' => pipeline(
                $config['task'],
                $config['model'],
                quantized: $config['quantized'] ?? true,
                modelFilename: $config['model_filename'] ?? null,
            ),
            'cross-encoder' => [
                'tokenizer' => AutoTokenizer::fromPretrained($config['model']),
                'model' => AutoModelForSequenceClassification::fromPretrained(
                    $config['model'],
                    $config['quantized'] ?? true,
                ),
            ],
            default => throw new InvalidArgumentException("Unknown engine kind: {$config['kind']}"),
        };
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        Transformers::setup()
            ->setCacheDir(config('crunch.cache_dir'))
            ->apply();

        $this->booted = true;
    }
}
