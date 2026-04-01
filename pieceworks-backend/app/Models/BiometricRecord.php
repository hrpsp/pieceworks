<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricRecord extends Model
{
    protected $table = 'biometric_records';

    protected $fillable = [
        'worker_id',
        'device_id',
        'punch_time',
        'punch_type',
        'synced_from_timbridge',
    ];

    protected function casts(): array
    {
        return [
            'punch_time'            => 'datetime',
            'synced_from_timbridge' => 'boolean',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
