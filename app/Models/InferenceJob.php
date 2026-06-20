<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasUid;
use Illuminate\Database\Eloquent\Model;

/**
 * Tracks an asynchronous inference job (e.g. transcription) for poll-based retrieval.
 *
 * @property string $uid
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
        'error',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
