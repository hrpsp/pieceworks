<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorSettlement extends Model
{
    protected $table = 'contractor_settlements';

    protected $fillable = [
        'contractor_id',
        'payroll_run_id',
        'week_ref',
        'total_pairs',
        'contracted_rate_avg',
        'bata_owes',
        'workers_paid',
        'contractor_margin',
        'settlement_status',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'total_pairs'         => 'integer',
            'contracted_rate_avg' => 'decimal:4',
            'bata_owes'           => 'decimal:2',
            'workers_paid'        => 'decimal:2',
            'contractor_margin'   => 'decimal:2',
            'settled_at'          => 'datetime',
        ];
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(WeeklyPayrollRun::class, 'payroll_run_id');
    }
}
