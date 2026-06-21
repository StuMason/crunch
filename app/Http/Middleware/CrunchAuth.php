<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiUsage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates inference requests by either:
 *  - a Sanctum personal access token (per-token rate limit + monthly quota + usage), or
 *  - the legacy master key (config crunch.api_key) for the /crunch skill + back-compat.
 *
 * Records one ApiUsage row per authenticated request on terminate().
 */
class CrunchAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = (string) $request->bearerToken();

        if ($bearer === '') {
            return $this->deny('Missing API key.', 401);
        }

        // Legacy master key — logged with a null token id.
        $master = (string) config('crunch.api_key');
        if ($master !== '' && hash_equals($master, $bearer)) {
            $request->attributes->set('crunch_t0', hrtime(true));

            return $next($request);
        }

        $token = PersonalAccessToken::findToken($bearer);

        if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
            return $this->deny('Invalid or expired API key.', 401);
        }

        $perMinute = (int) ($token->rate_limit_per_minute ?: 120);
        $key = "crunch-token:{$token->id}";

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            $retryAfter = RateLimiter::availableIn($key);
            $response = $this->deny("Rate limit exceeded. Retry in {$retryAfter}s.", 429);
            $response->headers->set('Retry-After', (string) $retryAfter);
            $this->attachRateLimitHeaders($response, $perMinute, 0, $retryAfter);

            return $response;
        }

        RateLimiter::hit($key, 60);

        if ($this->quotaExceeded($token)) {
            return $this->deny('Monthly quota exceeded.', 429);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('crunch_token_id', $token->id);
        $request->attributes->set('crunch_t0', hrtime(true));
        $request->setUserResolver(fn () => $token->tokenable);

        $response = $next($request);

        // Standard rate-limit headers so OpenAI/HTTP SDKs can back off intelligently.
        $this->attachRateLimitHeaders(
            $response,
            $perMinute,
            max(0, RateLimiter::remaining($key, $perMinute)),
            RateLimiter::availableIn($key),
        );

        return $response;
    }

    private function attachRateLimitHeaders(Response $response, int $limit, int $remaining, int $reset): void
    {
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);
        $response->headers->set('X-RateLimit-Reset', (string) $reset);
    }

    public function terminate(Request $request, Response $response): void
    {
        $t0 = $request->attributes->get('crunch_t0');

        if ($t0 === null) {
            return; // unauthenticated request — not logged
        }

        // Usage logging must never affect the response — swallow any failure.
        try {
            ApiUsage::create([
                'token_id' => $request->attributes->get('crunch_token_id'),
                'endpoint' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((hrtime(true) - $t0) / 1e6),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function quotaExceeded(PersonalAccessToken $token): bool
    {
        if ($token->monthly_limit === null) {
            return false;
        }

        $used = ApiUsage::where('token_id', $token->id)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->count();

        return $used >= (int) $token->monthly_limit;
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['error' => $message], $status);
    }
}
