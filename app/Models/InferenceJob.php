<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tracks an asynchronous inference job (e.g. transcription) for poll-based retrieval.
 *
 * @property string $uid
 * @property array<string, mixed>|null $result
 * @property array{stage: string, done?: int, total?: int}|null $progress where a long-running job is up to (null once completed)
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 */
class InferenceJob extends Model
{
    use HasUid;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'status',
        'model',
        'result',
        'progress',
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'progress' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
