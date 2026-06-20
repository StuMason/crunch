<?php

declare(strict_types=1);

use App\Actions\Inference\Embed;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crunch.api_key' => 'master-key']);
    $this->mock(Embed::class)->shouldReceive('handle')->andReturn([[0.1, 0.2]])->byDefault();
});

it('authenticates an inference request with a Sanctum token', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('app')->plainTextToken;

    $this->withToken($plain)
        ->postJson('/embed', ['inputs' => 'hello'])
        ->assertOk();
});

it('rejects a revoked token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('app');
    $plain = $token->plainTextToken;
    $token->accessToken->delete();

    $this->withToken($plain)
        ->postJson('/embed', ['inputs' => 'hello'])
        ->assertStatus(401);
});

it('still accepts the legacy master key', function () {
    $this->withToken('master-key')
        ->postJson('/embed', ['inputs' => 'hello'])
        ->assertOk();
});

it('lets an authenticated user mint a token (shown once)', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/tokens', ['name' => 'prod', 'rate_limit_per_minute' => 100])
        ->assertRedirect()
        ->assertSessionHas('newToken');

    expect($user->tokens()->where('name', 'prod')->exists())->toBeTrue();
});

it('lets a user revoke their token', function () {
    $user = User::factory()->create();
    $token = $user->createToken('temp')->accessToken;

    $this->actingAs($user)
        ->delete("/tokens/{$token->id}")
        ->assertRedirect();

    expect($user->tokens()->whereKey($token->id)->exists())->toBeFalse();
});
