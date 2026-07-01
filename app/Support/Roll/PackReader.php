<?php

declare(strict_types=1);

namespace App\Support\Roll;

use App\DataTransferObjects\Roll\Pack;
use App\DataTransferObjects\Roll\PackEvent;
use App\DataTransferObjects\Roll\PackManifest;
use JsonException;
use RuntimeException;

/**
 * Loads a roll take directory into a {@see Pack}: parses `manifest.json` and streams the
 * `metadata.jsonl` telemetry into typed events sorted on the shared clock.
 *
 * Tolerant of the things that are legitimately variable in a real pack — telemetry may be
 * absent (`metadata: null`), a malformed jsonl line is skipped rather than failing the
 * whole take, and the `keyframes/` directory is optional — but strict about the contract it
 * can't work without (a readable manifest + the screen file it references).
 */
class PackReader
{
    public function read(string $directory): Pack
    {
        $directory = rtrim($directory, '/');
        $manifestPath = $directory.'/manifest.json';

        if (! is_file($manifestPath)) {
            throw new RuntimeException("Pack manifest not found: {$manifestPath}");
        }

        $manifest = PackManifest::fromArray($this->decode((string) file_get_contents($manifestPath), $manifestPath));

        if (! is_file($directory.'/'.$manifest->screenFile)) {
            throw new RuntimeException("Pack screen file missing: {$manifest->screenFile}");
        }

        $events = $manifest->metadataFile === null
            ? []
            : $this->readEvents($directory.'/'.$manifest->metadataFile);

        return new Pack($directory, $manifest, $events, $this->readKeyframes($directory));
    }

    /**
     * Discover roll's pre-rendered screen keyframes: `keyframes/<t_ms>.png`, keyed by their
     * screen-clock t_ms (the same clock as {@see PackManifest::screenSecondsAt()}). These let the
     * OCR stage read lossless PNGs roll already captured at the salient moments instead of
     * re-extracting those frames from `screen.mp4`. Absent directory / non-numeric names are
     * simply ignored — the pipeline falls back to extracting every frame itself.
     *
     * @return array<int, string> t_ms => absolute PNG path, ascending by t_ms
     */
    private function readKeyframes(string $directory): array
    {
        $dir = $directory.'/keyframes';
        if (! is_dir($dir)) {
            return [];
        }

        $keyframes = [];
        foreach (glob($dir.'/*.png') ?: [] as $path) {
            $name = basename($path, '.png');
            if ($name === '' || ! ctype_digit($name)) {
                continue;
            }
            $keyframes[(int) $name] = $path;
        }

        ksort($keyframes);

        return $keyframes;
    }

    /**
     * @return list<PackEvent>
     */
    private function readEvents(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $events = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                /** @var array<string, mixed> $row */
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                // A single corrupt telemetry line shouldn't sink the whole take — skip it.
                continue;
            }

            $events[] = PackEvent::fromArray($row);
        }

        usort($events, fn (PackEvent $a, PackEvent $b): int => $a->tMs <=> $b->tMs);

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json, string $path): array
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Invalid JSON in {$path}: {$e->getMessage()}", previous: $e);
        }

        return $data;
    }
}
