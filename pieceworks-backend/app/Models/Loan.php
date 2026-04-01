<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'amount',
        'weekly_emi',
        'disbursed_by',
        'outstanding_balance',
        'notes',
        'total_weeks',
        'disbursed_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount'              => 'decimal:2',
            'weekly_emi'          => 'decimal:2',
            'outstanding_balance' => 'decimal:2',
            'disbursed_at'        => 'date',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }
}
