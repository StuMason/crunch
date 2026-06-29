<?php

declare(strict_types=1);

use App\Actions\Inference\Ocr;
use App\Inference\InferenceManager;
use App\Listeners\PrewarmModels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Auth via the legacy master-key path; array cache so atomic locks are deterministic.
    config([
        'crunch.api_key' => 'test-key',
        'cache.default' => 'array',
    ]);
});

it('lets a vision request through when a slot is free', function () {
    config(['crunch.vision.max_inflight' => 2]);
    $this->mock(Ocr::class)->shouldReceive('handle')->andReturn('HELLO');

    $this->withToken('test-key')
        ->postJson('/ocr', ['image' => base64_encode('fake-img')])
        ->assertOk()
        ->assertJsonPath('text', 'HELLO');
});

it('returns 429 + Retry-After when the vision sidecar is at capacity', function () {
    config(['crunch.vision.max_inflight' => 1]);

    // Occupy the only slot, exactly as an in-flight request would.
    $held = Cache::lock('inflight:vision:0', 60);
    expect($held->get())->toBeTrue();

    $this->withToken('test-key')
        ->postJson('/ocr', ['image' => base64_encode('fake-img')])
        ->assertStatus(429)
        ->assertHeader('Retry-After', '2')
        ->assertJsonStructure(['error']);

    $held->release();
});

it('releases the slot after the request so the next one succeeds', function () {
    config(['crunch.vision.max_inflight' => 1]);
    $this->mock(Ocr::class)->shouldReceive('handle')->andReturn('OK');

    // Two sequential requests with a single slot both succeed — proves release() runs.
    foreach (range(1, 2) as $_) {
        $this->withToken('test-key')
            ->postJson('/ocr', ['image' => base64_encode('fake-img')])
            ->assertOk();
    }
});

it('does not guard the in-process classify-image endpoint', function () {
    config(['crunch.vision.max_inflight' => 1]);
    $this->mock(\App\Actions\Inference\ClassifyImage::class)
        ->shouldReceive('handle')->andReturn([['label' => 'a cat', 'score' => 0.9]]);

    // Even with the vision slot held, classify-image (CLIP, in-process) is unaffected.
    Cache::lock('inflight:vision:0', 60)->get();

    $this->withToken('test-key')
        ->postJson('/classify-image', ['image' => base64_encode('x'), 'labels' => ['a cat']])
        ->assertOk();
});

it('prewarms in-process capabilities but skips sidecar-backed ones', function () {
    config(['crunch.warm' => ['embed', 'caption']]); // caption is kind: sidecar

    $manager = Mockery::mock(InferenceManager::class);
    $manager->shouldReceive('config')->with('embed')->andReturn(['kind' => 'pipeline']);
    $manager->shouldReceive('config')->with('caption')->andReturn(['kind' => 'sidecar']);
    $manager->shouldReceive('engine')->with('embed')->once();
    $manager->shouldReceive('engine')->with('caption')->never();

    (new PrewarmModels($manager))->handle((object) []);

    // Mockery expectations (->once()/->never()) are asserted on teardown.
    expect(true)->toBeTrue();
});
