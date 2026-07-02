<?php

declare(strict_types=1);

use App\Actions\Roll\ProcessPack;
use App\Jobs\ProcessPackJob;
use App\Models\InferenceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crunch.api_key' => 'test-key']);
    Storage::fake('local');
});

it('queues a pack job from an uploaded archive and returns a pollable job', function () {
    Queue::fake();

    $response = $this->withToken('test-key')
        ->post('/pack', ['pack' => UploadedFile::fake()->create('rec-1782821238979.tar.gz', 64)])
        ->assertStatus(202)
        ->assertJsonPath('type', 'pack')
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('model', 'roll-pack-v1');

    // packId is derived from the archive name (compression suffix stripped)
    Queue::assertPushed(ProcessPackJob::class, fn ($job) => $job->packId === 'rec-1782821238979');
    expect(InferenceJob::count())->toBe(1);
    expect($response->json('id'))->not->toBeEmpty();
    expect($response->json('poll_url'))->toContain('/jobs/');
});

it('rejects a request with no pack file', function () {
    Queue::fake();

    $this->withToken('test-key')
        ->postJson('/pack', [])
        ->assertStatus(422);

    Queue::assertNothingPushed();
    expect(InferenceJob::count())->toBe(0);
});

it('requires authentication', function () {
    $this->post('/pack', ['pack' => UploadedFile::fake()->create('p.tar', 1)])
        ->assertStatus(401);
});

it('persists progress reports through the job and clears them on completion', function () {
    // A minimal but real archive (manifest.json in a wrapper dir) so unpack() succeeds.
    $take = sys_get_temp_dir().'/pack-job-'.uniqid();
    mkdir($take, 0775, true);
    file_put_contents("{$take}/manifest.json", '{}');
    $archive = "{$take}.tar";
    exec('tar -cf '.escapeshellarg($archive).' -C '.escapeshellarg(dirname($take)).' '.escapeshellarg(basename($take)));

    $job = InferenceJob::create(['type' => 'pack', 'status' => InferenceJob::STATUS_QUEUED, 'model' => 'roll-pack-v1']);

    $seenMidRun = null;
    $this->mock(ProcessPack::class, function ($mock) use (&$seenMidRun) {
        $mock->shouldReceive('handle')->once()->andReturnUsing(
            function (string $packDir, string $packId, ?callable $onProgress) use (&$seenMidRun): array {
                $onProgress(['stage' => 'ocr', 'done' => 3, 'total' => 9]);
                $seenMidRun = InferenceJob::sole()->progress;

                return ['pack_id' => $packId];
            }
        );
    });

    (new ProcessPackJob($job->id, $archive, 'rec-test'))->handle(app(ProcessPack::class));

    expect($seenMidRun)->toBe(['stage' => 'ocr', 'done' => 3, 'total' => 9]);

    $job->refresh();
    expect($job->status)->toBe(InferenceJob::STATUS_COMPLETED);
    expect($job->progress)->toBeNull();
    expect($job->result)->toBe(['pack_id' => 'rec-test']);
});

it('surfaces the worker progress in the poll envelope while processing', function () {
    $job = InferenceJob::create([
        'type' => 'pack',
        'status' => InferenceJob::STATUS_PROCESSING,
        'model' => 'roll-pack-v1',
        'progress' => ['stage' => 'ocr', 'done' => 8, 'total' => 240],
    ]);

    $this->withToken('test-key')
        ->getJson("/jobs/{$job->uid}")
        ->assertOk()
        ->assertJsonPath('status', 'processing')
        ->assertJsonPath('progress.stage', 'ocr')
        ->assertJsonPath('progress.done', 8)
        ->assertJsonPath('progress.total', 240);
});
