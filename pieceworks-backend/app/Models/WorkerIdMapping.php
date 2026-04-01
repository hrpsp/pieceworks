<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerIdMapping extends Model
{
    protected $table = 'worker_id_mapping';

    protected $fillable = [
        'external_worker_id',
        'pieceworks_worker_id',
        'source_system',
        'created_by',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'pieceworks_worker_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
