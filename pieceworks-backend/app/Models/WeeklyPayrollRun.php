<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyPayrollRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'week_ref',
        'start_date',
        'end_date',
        'status',
        'total_gross',
        'total_topups',
        'total_deductions',
        'total_net',
        'locked_at',
        'locked_by',
        'released_at',
        'released_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date'   => 'date',
            'end_date'     => 'date',
            'locked_at'    => 'datetime',
            'released_at'  => 'datetime',
            'total_gross'       => 'decimal:2',
            'total_topups'      => 'decimal:2',
            'total_deductions'  => 'decimal:2',
            'total_net'         => 'decimal:2',
        ];
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function releaser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function workerPayrolls(): HasMany
    {
        return $this->hasMany(WorkerWeeklyPayroll::class, 'payroll_run_id');
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(PayrollException::class, 'payroll_run_id');
    }
}
