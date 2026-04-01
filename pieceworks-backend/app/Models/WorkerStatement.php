<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerStatement extends Model
{
    protected $table = 'worker_statements';

    protected $fillable = [
        'worker_id',
        'payroll_run_id',
        'week_ref',
        'statement_data',
        'generated_at',
        'whatsapp_sent',
        'whatsapp_sent_at',
        'whatsapp_status',
        'dispute_window_closes_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_data'          => 'array',
            'generated_at'            => 'datetime',
            'whatsapp_sent'           => 'boolean',
            'whatsapp_sent_at'        => 'datetime',
            'dispute_window_closes_at'=> 'datetime',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(WeeklyPayrollRun::class, 'payroll_run_id');
    }
}
