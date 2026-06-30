<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Roll;

/**
 * A parsed roll take: its {@see PackManifest} plus the ordered telemetry {@see PackEvent}s,
 * rooted at the directory the media files live in. This is the in-memory form the pack
 * processor walks to build crunch.json.
 */
final readonly class Pack
{
    /**
     * @param  list<PackEvent>  $events  ordered by t_ms
     */
    public function __construct(
        public string $directory,
        public PackManifest $manifest,
        public array $events,
    ) {}

    /**
     * Editing-relevant events only (drops cursor noise) — the rows that become `events[]`
     * in crunch.json and anchor the click × OCR × transcript join.
     *
     * @return list<PackEvent>
     */
    public function interactions(): array
    {
        return array_values(array_filter($this->events, fn (PackEvent $e): bool => $e->isInteraction()));
    }

    /**
     * @return list<PackEvent>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->events, fn (PackEvent $e): bool => $e->type === $type));
    }

    /**
     * Resolve a pack-relative filename to an absolute path INSIDE the pack directory.
     * Defensive: only the basename is used, so a hostile value can never escape the directory.
     */
    public function absolutePath(string $file): string
    {
        return rtrim($this->directory, '/').'/'.basename($file);
    }
}
