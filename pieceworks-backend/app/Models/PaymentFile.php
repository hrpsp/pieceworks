<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentFile extends Model
{
    protected $table = 'payment_files';

    protected $fillable = [
        'payroll_run_id',
        'file_type',
        'file_path',
        'total_amount',
        'worker_count',
        'generated_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount'  => 'decimal:2',
            'worker_count'  => 'integer',
            'generated_at'  => 'datetime',
            'released_at'   => 'datetime',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(WeeklyPayrollRun::class, 'payroll_run_id');
    }
}
