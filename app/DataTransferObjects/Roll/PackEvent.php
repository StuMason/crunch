<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Roll;

/**
 * One row of a pack's `metadata.jsonl` — an input/semantic telemetry event stamped with
 * `t_ms` (ms since {@see PackManifest::$t0}). Roll writes six shapes (click, drag, cursor,
 * key, scroll, app_focus); clicks/drags also carry optional `app`/`window` and an
 * accessibility context (`ax{role,label,bounds}`) describing exactly what was under the
 * pointer — the highest-value signal for the join, so it's surfaced first-class here.
 *
 * Only the fields the join actually uses are promoted; everything else stays in {@see $raw}.
 *
 * @phpstan-type AxShape array{role?:string, label?:string, bounds?:array<int, float>}
 */
final readonly class PackEvent
{
    /**
     * @param  array<string, mixed>  $ax  accessibility context (role/label/bounds), may be empty
     * @param  array<string, mixed>  $raw  the full original row, for fields not promoted here
     */
    public function __construct(
        public string $type,
        public int $tMs,
        public ?int $x,
        public ?int $y,
        public ?string $button,
        public ?string $app,
        public ?string $window,
        public array $ax,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $row  one decoded metadata.jsonl line
     */
    public static function fromArray(array $row): self
    {
        /** @var array<string, mixed> $ax */
        $ax = is_array($row['ax'] ?? null) ? $row['ax'] : [];

        return new self(
            type: (string) ($row['type'] ?? 'unknown'),
            tMs: (int) ($row['t_ms'] ?? 0),
            x: isset($row['x']) ? (int) $row['x'] : null,
            y: isset($row['y']) ? (int) $row['y'] : null,
            button: isset($row['button']) ? (string) $row['button'] : null,
            app: isset($row['app']) ? (string) $row['app'] : null,
            window: isset($row['window']) ? (string) $row['window'] : null,
            ax: $ax,
            raw: $row,
        );
    }

    /**
     * The accessibility label of the clicked element, if any — e.g. "Deploy" for a button.
     * This is the join's first choice for "what was interacted with" before falling back to OCR.
     */
    public function axLabel(): ?string
    {
        $label = $this->ax['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    public function axRole(): ?string
    {
        $role = $this->ax['role'] ?? null;

        return is_string($role) && $role !== '' ? $role : null;
    }

    /**
     * Is this an interaction worth joining/representing as an event in crunch.json?
     * Cursor moves are ~90% of telemetry volume and pure noise for editing — excluded here
     * (they stay in the raw metadata.jsonl if ever needed).
     */
    public function isInteraction(): bool
    {
        return $this->type !== 'cursor';
    }
}
