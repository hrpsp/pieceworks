<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $table = 'attendance_records';

    protected $fillable = [
        'worker_id',
        'work_date',
        'status',
        'idle_reason',
        'biometric_punch_in',
        'biometric_punch_out',
        'recorded_by',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'work_date'           => 'date',
            'biometric_punch_in'  => 'datetime',
            'biometric_punch_out' => 'datetime',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
