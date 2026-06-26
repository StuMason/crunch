<?php

declare(strict_types=1);

use App\Actions\Inference\Caption;
use App\Actions\Inference\ClassifyImage;
use App\Actions\Inference\ClassifyText;
use App\Actions\Inference\DetectObjects;
use App\Actions\Inference\Ocr;
use App\Actions\Inference\Rerank;
use App\Actions\Inference\Transcribe;
use App\Jobs\TranscribeJob;
use App\Models\InferenceJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['crunch.api_key' => 'test-key']);
});

it('reranks texts against a query (Cohere shape)', function () {
    $this->mock(Rerank::class)->shouldReceive('handle')->andReturn([
        ['index' => 1, 'relevance_score' => 0.91],
        ['index' => 0, 'relevance_score' => 0.02],
    ]);

    $this->withToken('test-key')
        ->postJson('/rerank', ['query' => 'q', 'texts' => ['not', 'relevant']])
        ->assertOk()
        ->assertJsonPath('results.0.index', 1)
        ->assertJsonPath('results.0.relevance_score', 0.91);
});

it('classifies sentiment', function () {
    $this->mock(ClassifyText::class)->shouldReceive('handle')
        ->with('sentiment', 'lovely')
        ->andReturn([['label' => 'admiration', 'score' => 0.8]]);

    $this->withToken('test-key')
        ->postJson('/sentiment', ['inputs' => 'lovely'])
        ->assertOk()
        ->assertJsonPath('results.0.label', 'admiration');
});

it('moderates content (OpenAI moderations shape)', function () {
    $this->mock(ClassifyText::class)->shouldReceive('handle')
        ->with('moderate', 'bad words')
        ->andReturn([['label' => 'H', 'score' => 0.95], ['label' => 'OK', 'score' => 0.05]]);

    $this->withToken('test-key')
        ->postJson('/moderate', ['inputs' => 'bad words'])
        ->assertOk()
        ->assertJsonPath('results.0.flagged', true)
        ->assertJsonPath('results.0.categories.H', true)
        ->assertJsonPath('results.0.category_scores.H', 0.95);
});

it('does not flag safe content', function () {
    $this->mock(ClassifyText::class)->shouldReceive('handle')
        ->with('moderate', 'have a lovely day')
        ->andReturn([['label' => 'OK', 'score' => 0.99], ['label' => 'H', 'score' => 0.01]]);

    $this->withToken('test-key')
        ->postJson('/moderate', ['inputs' => 'have a lovely day'])
        ->assertOk()
        ->assertJsonPath('results.0.flagged', false)
        ->assertJsonPath('results.0.categories.H', false);
});

it('returns all categories and forces the winning one true when flagged below 0.5', function () {
    // Low-confidence violence: top class isn't OK, but no score crosses 0.5.
    $this->mock(ClassifyText::class)->shouldReceive('handle')
        ->with('moderate', 'borderline')
        ->andReturn([
            ['label' => 'V', 'score' => 0.25],
            ['label' => 'OK', 'score' => 0.2],
            ['label' => 'H', 'score' => 0.1],
        ]);

    $this->withToken('test-key')
        ->postJson('/moderate', ['inputs' => 'borderline'])
        ->assertOk()
        ->assertJsonPath('results.0.flagged', true)
        ->assertJsonPath('results.0.categories.V', true)   // winner forced true for consistency
        ->assertJsonPath('results.0.categories.H', false)
        ->assertJsonPath('results.0.category_scores.H', 0.1); // all categories present
});

it('classifies an image against labels', function () {
    $this->mock(ClassifyImage::class)->shouldReceive('handle')
        ->andReturn([['label' => 'a cat', 'score' => 0.97]]);

    $this->withToken('test-key')
        ->postJson('/classify-image', ['image' => base64_encode('fake-img'), 'labels' => ['a cat', 'a dog']])
        ->assertOk()
        ->assertJsonPath('results.0.label', 'a cat');
});

it('captions an image', function () {
    $this->mock(Caption::class)->shouldReceive('handle')->andReturn('a cat on a mat');

    $this->withToken('test-key')
        ->postJson('/caption', ['image' => base64_encode('fake-img')])
        ->assertOk()
        ->assertJsonPath('caption', 'a cat on a mat');
});

it('reads text from an image (OCR)', function () {
    $this->mock(Ocr::class)->shouldReceive('handle')->andReturn('STOP');

    $this->withToken('test-key')
        ->postJson('/ocr', ['image' => base64_encode('fake-img')])
        ->assertOk()
        ->assertJsonPath('text', 'STOP');
});

it('detects objects in an image', function () {
    $this->mock(DetectObjects::class)->shouldReceive('handle')
        ->andReturn([['label' => 'dog', 'box' => [1.5, 2.5, 3.5, 4.5]]]);

    $this->withToken('test-key')
        ->postJson('/detect', ['image' => base64_encode('fake-img')])
        ->assertOk()
        ->assertJsonPath('objects.0.label', 'dog')
        ->assertJsonPath('objects.0.box.2', 3.5);
});

it('zips Florence-2 detection output into objects (sidecar)', function () {
    config(['crunch.vision.url' => 'http://vision:9000']);

    Http::fake(['vision:9000/caption' => Http::response([
        'model' => 'microsoft/Florence-2-base',
        'task' => '<OD>',
        'result' => ['bboxes' => [[10, 20, 30, 40], [5, 6, 7, 8]], 'labels' => ['dog', 'ball']],
    ], 200)]);

    $img = tempnam(sys_get_temp_dir(), 'od');
    file_put_contents($img, 'x');

    $objects = app(DetectObjects::class)->handle($img);

    expect($objects)->toHaveCount(2);
    expect($objects[0])->toBe(['label' => 'dog', 'box' => [10.0, 20.0, 30.0, 40.0]]);
});

it('extracts and trims OCR text (sidecar)', function () {
    config(['crunch.vision.url' => 'http://vision:9000']);

    Http::fake(['vision:9000/caption' => Http::response([
        'model' => 'microsoft/Florence-2-base',
        'task' => '<OCR>',
        'result' => '  hello world  ',
    ], 200)]);

    $img = tempnam(sys_get_temp_dir(), 'ocr');
    file_put_contents($img, 'x');

    expect(app(Ocr::class)->handle($img))->toBe('hello world');
});

it('captions an image via the vision sidecar (Florence-2)', function () {
    config(['crunch.vision.url' => 'http://vision:9000']);

    Http::fake([
        'vision:9000/caption' => Http::response([
            'model' => 'microsoft/Florence-2-base',
            'task' => '<CAPTION>',
            'caption' => 'A brown dog standing on green grass.',
        ], 200),
    ]);

    $imagePath = tempnam(sys_get_temp_dir(), 'vision-test');
    file_put_contents($imagePath, 'fake-image-bytes');

    $caption = app(Caption::class)->handle($imagePath);

    expect($caption)->toBe('A brown dog standing on green grass.');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/caption'));
});

it('queues a transcription and returns a pollable job', function () {
    Queue::fake();

    $audio = base64_encode('fake-wav-bytes');

    $response = $this->withToken('test-key')
        ->postJson('/transcribe', ['audio' => $audio])
        ->assertStatus(202)
        ->assertJsonPath('type', 'transcribe')
        ->assertJsonPath('status', 'queued');

    Queue::assertPushed(TranscribeJob::class);
    expect(InferenceJob::count())->toBe(1);
    expect($response->json('id'))->not->toBeEmpty();
});

it('stores a verbatim transcript with word timestamps from the asr sidecar', function () {
    config(['crunch.asr.url' => 'http://asr:9000']);

    // The sidecar's raw verbatim payload (OpenAI verbose_json) — fillers intact ("um"),
    // word-level times under the standard `word` key.
    Http::fake([
        'asr:9000/transcribe' => Http::response([
            'task' => 'transcribe',
            'language' => 'en',
            'duration' => 3.2,
            'text' => 'So um I built a scraper.',
            'words' => [
                ['word' => 'So', 'start' => 0.0, 'end' => 0.2],
                ['word' => 'um', 'start' => 0.24, 'end' => 0.5],
                ['word' => 'I', 'start' => 0.62, 'end' => 0.7],
                ['word' => 'built', 'start' => 0.7, 'end' => 1.0],
            ],
            'model' => 'large-v3-turbo',
            'infer_secs' => 1.1,
        ], 200),
    ]);

    $audioPath = tempnam(sys_get_temp_dir(), 'asr-test');
    file_put_contents($audioPath, 'fake-audio-bytes');

    $job = InferenceJob::create([
        'type' => 'transcribe',
        'status' => InferenceJob::STATUS_QUEUED,
        'model' => config('crunch.models.transcribe.model'),
    ]);

    (new TranscribeJob($job->id, $audioPath))->handle(app(Transcribe::class));

    $job->refresh();
    expect($job->status)->toBe(InferenceJob::STATUS_COMPLETED);
    expect($job->model)->toBe('large-v3-turbo');
    expect($job->result['task'])->toBe('transcribe');
    expect($job->result['text'])->toBe('So um I built a scraper.');
    expect($job->result['duration'])->toBe(3.2);
    expect($job->result['words'])->toHaveCount(4);
    // The filler must survive end-to-end — verbatim is the whole reason for the sidecar.
    expect($job->result['words'][1]['word'])->toBe('um');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/transcribe'));
});

it('polls a job by uid', function () {
    $job = InferenceJob::create([
        'type' => 'transcribe',
        'status' => InferenceJob::STATUS_COMPLETED,
        'result' => ['text' => 'hello world'],
        'completed_at' => now(),
    ]);

    $this->withToken('test-key')
        ->getJson("/jobs/{$job->uid}")
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('result.text', 'hello world');
});

it('rejects all endpoints without a key', function () {
    $this->postJson('/rerank', ['query' => 'q', 'texts' => ['a']])->assertStatus(401);
    $this->postJson('/sentiment', ['inputs' => 'x'])->assertStatus(401);
    $this->postJson('/transcribe', ['audio' => 'x'])->assertStatus(401);
});
