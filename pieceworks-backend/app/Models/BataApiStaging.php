<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BataApiStaging extends Model
{
    protected $table = 'bata_api_staging';

    protected $fillable = [
        'external_worker_id',
        'pieceworks_worker_id',
        'line_id',
        'style_code',
        'operation',
        'pairs_completed',
        'pairs_rejected',
        'work_date',
        'shift',
        'raw_payload',
        'source_tag',
        'validation_status',
        'validation_errors',
        'processed',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload'       => 'array',
            'validation_errors' => 'array',
            'work_date'         => 'date',
            'pairs_completed'   => 'integer',
            'pairs_rejected'    => 'integer',
            'processed'         => 'boolean',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'pieceworks_worker_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }
}
