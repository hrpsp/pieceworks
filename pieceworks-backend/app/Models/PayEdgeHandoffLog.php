<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayEdgeHandoffLog extends Model
{
    protected $table = 'payedge_handoff_logs';

    protected $fillable = [
        'payroll_run_id',
        'worker_id',
        'week_ref',
        'status',
        'attempts',
        'payload',
        'response',
        'error_message',
        'sent_at',
        'last_attempted_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'           => 'array',
            'response'          => 'array',
            'attempts'          => 'integer',
            'sent_at'           => 'datetime',
            'last_attempted_at' => 'datetime',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(WeeklyPayrollRun::class, 'payroll_run_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
