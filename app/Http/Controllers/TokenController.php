<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TokenController extends Controller
{
    /**
     * Mint a new API token (shown in plaintext exactly once).
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'monthly_limit' => ['nullable', 'integer', 'min:1'],
            'rate_limit_per_minute' => ['required', 'integer', 'min:1', 'max:6000'],
        ]);

        $token = $request->user()->createToken($validated['name']);

        $token->accessToken->forceFill([
            'monthly_limit' => $validated['monthly_limit'] ?? null,
            'rate_limit_per_minute' => $validated['rate_limit_per_minute'],
        ])->save();

        return back()->with('newToken', [
            'name' => $validated['name'],
            'plainText' => $token->plainTextToken,
        ]);
    }

    /**
     * Revoke a token belonging to the current user.
     */
    public function destroy(Request $request, int $token): RedirectResponse
    {
        $request->user()->tokens()->whereKey($token)->delete();

        return back()->with('status', 'Token revoked.');
    }
}
