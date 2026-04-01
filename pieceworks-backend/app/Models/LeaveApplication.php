<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveApplication extends Model
{
    protected $table = 'leave_applications';

    protected $fillable = [
        'worker_id',
        'leave_type',
        'from_date',
        'to_date',
        'days',
        'status',
        'leave_pay_amount',
        'avg_daily_earnings_basis',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'from_date'                => 'date',
            'to_date'                  => 'date',
            'days'                     => 'integer',
            'leave_pay_amount'         => 'decimal:2',
            'avg_daily_earnings_basis' => 'decimal:2',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
