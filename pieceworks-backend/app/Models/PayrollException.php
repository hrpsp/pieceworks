<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollException extends Model
{
    use HasFactory;

    protected $table = 'payroll_exceptions';

    protected $fillable = [
        'payroll_run_id',
        'worker_id',
        'worker_weekly_payroll_id',
        'exception_type',
        'description',
        'amount',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(WeeklyPayrollRun::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function workerWeeklyPayroll(): BelongsTo
    {
        return $this->belongsTo(WorkerWeeklyPayroll::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
