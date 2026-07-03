<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Roll;

/**
 * The `manifest.json` a roll take is finalized with — the index that ties every media
 * source and telemetry event to ONE shared host clock (`t0`, CACurrentMediaTime seconds).
 *
 * Reflects what roll's capture-swift actually writes (schema v0.0.14): all event `t_ms` are
 * milliseconds AFTER `t0`; each media file's first frame sits at `t0 + <its sync offset>`,
 * so seeking to an event time differs per source. The conversion helpers below own that math
 * — callers must never assume a file starts exactly at `t0`.
 *
 * @phpstan-type DisplayShape array{id:int, x:int, y:int, w:int, h:int}
 */
final readonly class PackManifest
{
    /**
     * @param  DisplayShape  $display
     */
    public function __construct(
        public string $version,
        public int $fps,
        public float $t0,
        public float $durationMs,
        public array $display,
        public string $screenFile,
        public ?string $cameraFile,
        public ?string $micFile,
        public float $cameraSyncOffsetMs,
        public float $micSyncOffsetMs,
        public ?string $metadataFile,
        public ?string $sysAudioFile = null,
        public float $sysAudioSyncOffsetMs = 0.0,
    ) {}

    /**
     * @param  array<string, mixed>  $data  decoded manifest.json
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string, mixed> $screen */
        $screen = is_array($data['screen'] ?? null) ? $data['screen'] : [];
        /** @var array<string, mixed>|null $camera */
        $camera = is_array($data['camera'] ?? null) ? $data['camera'] : null;
        /** @var array<string, mixed>|null $mic */
        $mic = is_array($data['mic'] ?? null) ? $data['mic'] : null;
        /** @var array<string, mixed>|null $sysAudio */
        $sysAudio = is_array($data['sysaudio'] ?? null) ? $data['sysaudio'] : null;

        // The media/metadata filenames come from an untrusted manifest.json and are only ever
        // joined onto the pack directory, so collapse each to a bare basename here — a manifest
        // can never make `screen.mp4` resolve to `../../etc/passwd`.
        return new self(
            version: (string) ($data['version'] ?? '0'),
            fps: (int) ($data['fps'] ?? 30),
            t0: (float) ($data['t0'] ?? 0),
            durationMs: (float) ($data['durationMs'] ?? 0),
            display: self::normalizeDisplay($data['display'] ?? []),
            screenFile: basename((string) ($screen['file'] ?? 'screen.mp4')),
            cameraFile: $camera === null ? null : self::cameraSource($camera),
            micFile: $mic === null ? null : basename((string) ($mic['file'] ?? 'mic.m4a')),
            cameraSyncOffsetMs: (float) ($data['cameraSyncOffsetMs'] ?? 0),
            micSyncOffsetMs: (float) ($data['micSyncOffsetMs'] ?? 0),
            metadataFile: isset($data['metadata']) ? basename((string) $data['metadata']) : null,
            sysAudioFile: $sysAudio === null ? null : basename((string) ($sysAudio['file'] ?? 'sysaudio.m4a')),
            sysAudioSyncOffsetMs: (float) ($data['sysAudioSyncOffsetMs'] ?? 0),
        );
    }

    /**
     * The camera file to analyse. Roll packs may ship a small proxy encode
     * (`camera.proxy.mp4`) INSTEAD of the full-res `camera.mp4`, which stays on the
     * recording Mac for rendering — when the manifest declares a proxy, analyse that.
     * The proxy is transcoded from the same capture, so `cameraSyncOffsetMs` and the
     * shared-clock seek math apply unchanged.
     *
     * @param  array<string, mixed>  $camera
     */
    private static function cameraSource(array $camera): string
    {
        $proxy = is_array($camera['proxy'] ?? null) ? $camera['proxy'] : null;
        $file = $proxy['file'] ?? null;

        return basename(is_string($file) && $file !== '' ? $file : (string) ($camera['file'] ?? 'camera.mp4'));
    }

    public function hasCamera(): bool
    {
        return $this->cameraFile !== null;
    }

    public function hasMic(): bool
    {
        return $this->micFile !== null;
    }

    public function hasSysAudio(): bool
    {
        return $this->sysAudioFile !== null;
    }

    /**
     * Seconds to seek into `sysaudio.m4a` for an event at `t_ms` (system audio starts
     * `sysAudioSyncOffsetMs` after t0).
     */
    public function sysAudioSecondsAt(int $tMs): float
    {
        return max(0.0, ($tMs - $this->sysAudioSyncOffsetMs) / 1000.0);
    }

    /**
     * Shared-clock `t_ms` for a word whose time is measured in seconds INTO `sysaudio.m4a`.
     * Inverse of {@see sysAudioSecondsAt()} — places the system-audio transcript on the same
     * timeline as the mic transcript and the input events so every track lines up.
     */
    public function sysAudioTMsForSeconds(float $sysAudioSeconds): int
    {
        return (int) round($this->sysAudioSyncOffsetMs + $sysAudioSeconds * 1000.0);
    }

    /**
     * Seconds to seek into `screen.mp4` for an event at `t_ms`. The screen track is anchored
     * at `t0` (its first frame is forced to t0 and keepalive re-emits through static stretches),
     * so a frame always exists at exactly this offset.
     */
    public function screenSecondsAt(int $tMs): float
    {
        return $tMs / 1000.0;
    }

    /**
     * Seconds to seek into `camera.mp4` for an event at `t_ms`. The camera starts
     * `cameraSyncOffsetMs` after t0, so its timeline is shifted back by that offset.
     */
    public function cameraSecondsAt(int $tMs): float
    {
        return max(0.0, ($tMs - $this->cameraSyncOffsetMs) / 1000.0);
    }

    /**
     * Seconds to seek into `mic.m4a` for an event at `t_ms` (mic starts `micSyncOffsetMs` after t0).
     */
    public function micSecondsAt(int $tMs): float
    {
        return max(0.0, ($tMs - $this->micSyncOffsetMs) / 1000.0);
    }

    /**
     * Shared-clock `t_ms` for a transcript word whose time is measured in seconds INTO
     * `mic.m4a`. Inverse of {@see micSecondsAt()} — used to place transcript words back onto
     * the same timeline as the input events so the join lines up.
     */
    public function micTMsForSeconds(float $micSeconds): int
    {
        return (int) round($this->micSyncOffsetMs + $micSeconds * 1000.0);
    }

    /**
     * @param  mixed  $display
     * @return DisplayShape
     */
    private static function normalizeDisplay($display): array
    {
        $d = is_array($display) ? $display : [];

        return [
            'id' => (int) ($d['id'] ?? 0),
            'x' => (int) ($d['x'] ?? 0),
            'y' => (int) ($d['y'] ?? 0),
            'w' => (int) ($d['w'] ?? 0),
            'h' => (int) ($d['h'] ?? 0),
        ];
    }
}
