<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Auth via the legacy master key; array cache so the inflight lock is deterministic.
    config(['crunch.api_key' => 'test-key', 'cache.default' => 'array']);
});

it('returns 504 (not 500) when the vision sidecar exceeds its deadline', function () {
    // cURL 28 is how a too-large/too-dense image surfaces (issue #11/#12).
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 90000 milliseconds'));

    $this->withToken('test-key')
        ->postJson('/ocr', ['image' => base64_encode('img')])
        ->assertStatus(504)
        ->assertJsonStructure(['error']);
});

it('returns 503 (not 500) when the vision sidecar is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to vision port 9000'));

    $this->withToken('test-key')
        ->postJson('/detect', ['image' => base64_encode('img')])
        ->assertStatus(503)
        ->assertJsonStructure(['error']);
});

it('propagates a 504 reported by the sidecar itself', function () {
    Http::fake(fn () => Http::response(['detail' => 'too slow'], 504));

    $this->withToken('test-key')
        ->postJson('/caption', ['image' => base64_encode('img')])
        ->assertStatus(504);
});
