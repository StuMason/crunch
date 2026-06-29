<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Auth via the legacy master key; array cache so the inflight lock is deterministic.
    config(['crunch.api_key' => 'test-key', 'cache.default' => 'array']);
});

it('returns 422 (not 500/504) when the vision sidecar exceeds its deadline', function () {
    // cURL 28 is how a too-large/too-dense image surfaces (issue #11/#12). We use 422,
    // not 504, because Cloudflare replaces origin 504 bodies with its own opaque page —
    // 4xx passes through so the "downscale or crop" message actually reaches the caller.
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30000 milliseconds'));

    $this->withToken('test-key')
        ->postJson('/ocr', ['image' => base64_encode('img')])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});

it('returns 503 (not 500) when the vision sidecar is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to vision port 9000'));

    $this->withToken('test-key')
        ->postJson('/detect', ['image' => base64_encode('img')])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

it('propagates a 503 reported by the sidecar itself', function () {
    Http::fake(fn () => Http::response(['detail' => 'unavailable'], 503));

    $this->withToken('test-key')
        ->postJson('/caption', ['image' => base64_encode('img')])
        ->assertStatus(503);
});
