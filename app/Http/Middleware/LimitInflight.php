<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps how many requests may be in-flight against a capacity-bound backend (e.g. the
 * single, synchronous vision sidecar) at once.
 *
 * The sidecar processes one image at a time on CPU, so unbounded concurrency just
 * queues requests until they blow the Octane execution-time limit and wedge the
 * worker pool (Cloudflare then returns 524s). This guard instead acquires one of
 * `$max` atomic lock "slots" before passing the request through; when every slot is
 * taken it returns a fast `429` + `Retry-After` so callers back off cleanly rather
 * than piling on. Slots auto-expire (lock TTL) if a worker dies mid-request.
 *
 * Usage (route middleware): `LimitInflight::class.':vision'` — reads
 * `crunch.{key}.max_inflight` and `crunch.{key}.timeout` from config.
 */
class LimitInflight
{
    public function handle(Request $request, Closure $next, string $key = 'vision'): Response
    {
        $max = max(1, (int) config("crunch.{$key}.max_inflight", 2));

        // Auto-release safety margin: comfortably longer than the backend's own timeout
        // so a crashed worker can't hold a slot forever.
        $ttl = (int) config("crunch.{$key}.timeout", 90) + 30;

        $lock = $this->acquireSlot($key, $max, $ttl);

        if (! $lock instanceof Lock) {
            return response()
                ->json(['error' => "Server busy: {$key} at capacity ({$max} concurrent). Retry shortly."], 429)
                ->header('Retry-After', '2');
        }

        try {
            return $next($request);
        } finally {
            $lock->release();
        }
    }

    /**
     * Try each slot in turn; return the first lock acquired, or null if all are taken.
     */
    private function acquireSlot(string $key, int $max, int $ttl): ?Lock
    {
        for ($slot = 0; $slot < $max; $slot++) {
            $lock = Cache::lock("inflight:{$key}:{$slot}", $ttl);

            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
