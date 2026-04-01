<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollReversal extends Model
{
    protected $table = 'payroll_reversals';

    protected $fillable = [
        'payroll_run_id',
        'reversal_type',
        'worker_id',
        'reason',
        'authorized_by',
        'reversed_workers',
        'total_amount_reversed',
        'payedge_notified',
        'payedge_notified_at',
        'payedge_response',
    ];

    protected function casts(): array
    {
        return [
            'reversed_workers'      => 'integer',
            'total_amount_reversed' => 'decimal:2',
            'payedge_notified'      => 'boolean',
            'payedge_notified_at'   => 'datetime',
            'payedge_response'      => 'array',
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

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }
}
