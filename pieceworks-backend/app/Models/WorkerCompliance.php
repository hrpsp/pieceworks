<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerCompliance extends Model
{
    protected $table = 'worker_compliance';

    protected $fillable = [
        'worker_id',
        'eobi_number',
        'pessi_number',
        'eobi_registered_at',
        'pessi_registered_at',
        'ntn_number',
        'tax_status',
        'wht_applicable',
    ];

    protected function casts(): array
    {
        return [
            'eobi_registered_at'  => 'date',
            'pessi_registered_at' => 'date',
            'wht_applicable'      => 'boolean',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
