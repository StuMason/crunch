<?php

declare(strict_types=1);

use App\Actions\Inference\Ocr;
use App\Jobs\OcrBatchJob;
use App\Models\InferenceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crunch.api_key' => 'test-key']);
});

it('queues a batch OCR job and returns a pollable job', function () {
    Queue::fake();

    $response = $this->withToken('test-key')
        ->postJson('/ocr/batch', ['crops' => [
            ['box' => [0, 0, 50, 20], 'image' => base64_encode('fake-crop-1')],
            ['box' => [0, 30, 50, 20], 'image' => base64_encode('fake-crop-2')],
        ]])
        ->assertStatus(202)
        ->assertJsonPath('type', 'ocr-batch')
        ->assertJsonPath('status', 'queued');

    Queue::assertPushed(OcrBatchJob::class, fn ($job) => count($job->crops) === 2 && $job->engine === 'tesseract');
    expect(InferenceJob::count())->toBe(1);
    expect($response->json('id'))->not->toBeEmpty();
    expect($response->json('poll_url'))->toContain('/jobs/');
});

it('threads a chosen engine onto the batch job and labels the job model', function () {
    Queue::fake();

    $response = $this->withToken('test-key')
        ->postJson('/ocr/batch', [
            'engine' => 'paddle',
            'psm' => 7,
            'crops' => [['box' => 0, 'image' => base64_encode('fake-crop')]],
        ])
        ->assertStatus(202)
        ->assertJsonPath('model', 'PaddleOCR PP-OCRv6');

    Queue::assertPushed(OcrBatchJob::class, fn ($job) => $job->engine === 'paddle' && $job->psm === 7);
    expect($response->json('id'))->not->toBeEmpty();
});

it('rejects an unknown engine on the batch endpoint', function () {
    Queue::fake();

    $this->withToken('test-key')
        ->postJson('/ocr/batch', ['engine' => 'floop', 'crops' => [['image' => base64_encode('x')]]])
        ->assertStatus(422);

    Queue::assertNothingPushed();
});

it('OCRs each crop and records one {box, text} result per crop in order', function () {
    // The action is mocked, so the temp files only need to exist for the unlink in finally.
    $paths = [tempnam(sys_get_temp_dir(), 'ocrb'), tempnam(sys_get_temp_dir(), 'ocrb')];

    $this->mock(Ocr::class)
        ->shouldReceive('handle')
        ->twice()
        ->andReturn('Upgrade Plan', 'Archived');

    $job = InferenceJob::create([
        'type' => 'ocr-batch',
        'status' => InferenceJob::STATUS_QUEUED,
        'model' => config('crunch.models.ocr.model'),
    ]);

    (new OcrBatchJob($job->id, [
        ['box' => [10, 10, 80, 24], 'path' => $paths[0]],
        ['box' => ['id' => 'thumb'], 'path' => $paths[1]],
    ]))->handle(app(Ocr::class));

    $job->refresh();
    expect($job->status)->toBe(InferenceJob::STATUS_COMPLETED);
    expect($job->result['count'])->toBe(2);
    expect($job->result['results'][0])->toMatchArray(['box' => [10, 10, 80, 24], 'text' => 'Upgrade Plan', 'error' => null]);
    expect($job->result['results'][1])->toMatchArray(['box' => ['id' => 'thumb'], 'text' => 'Archived', 'error' => null]);

    // Temp crops are cleaned up as they're processed.
    expect(file_exists($paths[0]))->toBeFalse();
    expect(file_exists($paths[1]))->toBeFalse();
});

it('isolates a failing crop as a per-crop error without sinking the batch', function () {
    $paths = [tempnam(sys_get_temp_dir(), 'ocrb'), tempnam(sys_get_temp_dir(), 'ocrb')];

    $this->mock(Ocr::class)
        ->shouldReceive('handle')
        ->twice()
        ->andReturnUsing(function () {
            static $n = 0;

            return ++$n === 1 ? 'good text' : throw new RuntimeException('sidecar timed out');
        });

    $job = InferenceJob::create(['type' => 'ocr-batch', 'status' => InferenceJob::STATUS_QUEUED]);

    (new OcrBatchJob($job->id, [
        ['box' => 0, 'path' => $paths[0]],
        ['box' => 1, 'path' => $paths[1]],
    ]))->handle(app(Ocr::class));

    $job->refresh();
    expect($job->status)->toBe(InferenceJob::STATUS_COMPLETED);
    expect($job->result['results'][0])->toMatchArray(['box' => 0, 'text' => 'good text', 'error' => null]);
    expect($job->result['results'][1]['text'])->toBeNull();
    expect($job->result['results'][1]['error'])->toContain('sidecar timed out');
});

it('polls a batch job by uid with the ocr-batch result shape', function () {
    $job = InferenceJob::create([
        'type' => 'ocr-batch',
        'status' => InferenceJob::STATUS_COMPLETED,
        'result' => ['count' => 1, 'results' => [['box' => [1, 2, 3, 4], 'text' => 'Save', 'error' => null]]],
        'completed_at' => now(),
    ]);

    $this->withToken('test-key')
        ->getJson("/jobs/{$job->uid}")
        ->assertOk()
        ->assertJsonPath('type', 'ocr-batch')
        ->assertJsonPath('result.count', 1)
        ->assertJsonPath('result.results.0.box', [1, 2, 3, 4])
        ->assertJsonPath('result.results.0.text', 'Save');
});

it('rejects an empty or oversized crops list', function () {
    $this->withToken('test-key')->postJson('/ocr/batch', ['crops' => []])->assertStatus(422);
    $this->withToken('test-key')->postJson('/ocr/batch', [])->assertStatus(422);
});

it('rejects a crop whose image is not valid base64', function () {
    Queue::fake();

    $this->withToken('test-key')
        ->postJson('/ocr/batch', ['crops' => [['box' => [0, 0, 1, 1], 'image' => '!!! not base64 !!!']]])
        ->assertStatus(422);

    Queue::assertNothingPushed();
    expect(InferenceJob::count())->toBe(0);
});

it('requires a key', function () {
    $this->postJson('/ocr/batch', ['crops' => [['image' => base64_encode('x')]]])->assertStatus(401);
});
