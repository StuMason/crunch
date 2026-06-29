<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

/**
 * The vision sidecar could not service the request: it exceeded the deadline (504) or is
 * unreachable (503). Rendered as a clean JSON error with the right status so API callers
 * can react — e.g. downscale-and-retry on a 504 — instead of seeing an opaque 500.
 *
 * See issues #11 (full-res frames time out) and #12 (timeout should be 504, not 500).
 */
class VisionUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Laravel calls render() on a thrown exception that defines it, so every vision
     * endpoint (/ocr, /caption, /detect) gets the correct status with no per-controller
     * try/catch.
     */
    public function render(): JsonResponse
    {
        return response()->json(['error' => $this->getMessage()], $this->status);
    }
}
