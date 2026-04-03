<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerWeeklyPayroll extends Model
{
    use HasFactory;

    protected $table = 'worker_weekly_payroll';

    protected $fillable = [
        'payroll_run_id',
        'worker_id',
        'contractor_id',
        'gross_earnings',
        'ot_premium',
        // CR-005: split OT columns
        'ot_regular_hours',
        'ot_regular_amount',
        'ot_night_hours',
        'ot_night_amount',
        'ot_extra_hours',
        'ot_extra_amount',
        'shift_allowance',
        'holiday_pay',
        'min_wage_supplement',
        'total_gross',
        'advance_deductions',
        'rejection_deductions',
        'loan_deductions',
        'other_deductions',
        'carry_forward_amount',
        'net_pay',
        'payment_method',
        'payment_status',
    ];

    protected function casts(): array
    {
        return [
            'gross_earnings'       => 'decimal:2',
            'ot_premium'           => 'decimal:2',
            'ot_regular_hours'     => 'decimal:2',
            'ot_regular_amount'    => 'decimal:2',
            'ot_night_hours'       => 'decimal:2',
            'ot_night_amount'      => 'decimal:2',
            'ot_extra_hours'       => 'decimal:2',
            'ot_extra_amount'      => 'decimal:2',
            'shift_allowance'      => 'decimal:2',
            'holiday_pay'          => 'decimal:2',
            'min_wage_supplement'  => 'decimal:2',
            'total_gross'          => 'decimal:2',
            'advance_deductions'   => 'decimal:2',
            'rejection_deductions' => 'decimal:2',
            'loan_deductions'      => 'decimal:2',
            'other_deductions'     => 'decimal:2',
            'carry_forward_amount' => 'decimal:2',
            'net_pay'              => 'decimal:2',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(WeeklyPayrollRun::class);
    }
}
