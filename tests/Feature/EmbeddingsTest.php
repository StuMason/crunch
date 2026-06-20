<?php

declare(strict_types=1);

use App\Actions\Inference\Embed;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crunch.api_key' => 'test-key']);

    // Stub the model so the HTTP layer is tested without loading ONNX.
    $this->mock(Embed::class, function ($mock) {
        $mock->shouldReceive('handle')->andReturnUsing(
            fn (array $texts) => array_map(fn () => [0.1, 0.2, 0.3], $texts),
        );
    });
});

it('rejects requests without a bearer key', function () {
    $this->postJson('/v1/embeddings', ['input' => 'hello'])
        ->assertStatus(401)
        ->assertJsonStructure(['error']);
});

it('rejects requests with the wrong key', function () {
    $this->withToken('nope')
        ->postJson('/v1/embeddings', ['input' => 'hello'])
        ->assertStatus(401);
});

it('returns OpenAI-shaped embeddings for an array of inputs', function () {
    $this->withToken('test-key')
        ->postJson('/v1/embeddings', ['input' => ['one', 'two']])
        ->assertOk()
        ->assertJsonPath('object', 'list')
        ->assertJsonPath('model', 'onnx-community/Qwen3-Embedding-0.6B-ONNX')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.object', 'embedding')
        ->assertJsonPath('data.1.index', 1)
        ->assertJsonPath('data.0.embedding', [0.1, 0.2, 0.3]);
});

it('returns a bare vector list on the native /embed endpoint', function () {
    $this->withToken('test-key')
        ->postJson('/embed', ['inputs' => 'just one'])
        ->assertOk()
        ->assertExactJson([[0.1, 0.2, 0.3]]);
});

it('validates that input is required', function () {
    $this->withToken('test-key')
        ->postJson('/v1/embeddings', [])
        ->assertStatus(422);
});
