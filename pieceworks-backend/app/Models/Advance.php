<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Advance extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'week_ref',
        'amount',
        'requires_approval',
        'approved_by',
        'approved_at',
        'payment_method',
        'notes',
        'deduction_week',
        'carry_weeks',
        'amount_deducted',
        'carried_weeks',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount'            => 'decimal:2',
            'amount_deducted'   => 'decimal:2',
            'carry_weeks'       => 'integer',
            'carried_weeks'     => 'integer',
            'requires_approval' => 'boolean',
            'approved_at'       => 'datetime',
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

    public function remainingAmount(): float
    {
        return round((float) $this->amount - (float) $this->amount_deducted, 2);
    }
}
