<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Interim bearer-key auth for the API while the walking skeleton is deployed.
 * Replaced by Sanctum per-token auth (with quotas + usage) in the management layer.
 */
class CrunchApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('crunch.api_key');
        $provided = (string) $request->bearerToken();

        if ($expected === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Invalid or missing API key.'], 401);
        }

        return $next($request);
    }
}
