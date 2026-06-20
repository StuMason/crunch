<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per authenticated inference request, for usage dashboards + quota counting.
 */
class ApiUsage extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'token_id',
        'endpoint',
        'status',
        'duration_ms',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
