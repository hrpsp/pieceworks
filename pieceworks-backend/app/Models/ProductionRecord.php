<?php

namespace App\Models;

use App\Observers\ProductionRecordObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ProductionRecordObserver::class)]
class ProductionRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'worker_id',
        'line_id',
        'rate_card_entry_id',
        'work_date',
        'shift',
        'style_sku_id',
        'task',
        'pairs_produced',
        'rate_amount',
        'gross_earnings',
        'source_tag',
        'wage_model_applied',        // CR-001: snapshot of wage model at calculation time
        'rate_detail',               // CR-001: human-readable breakdown for statements
        'shift_adjustment',
        'shift_adj_authorized_by',
        'shift_adj_reason',
        'supervisor_notes',
        'validation_status',
        'is_locked',
        'ghost_risk_level',
        'ghost_flagged_at',
        'billing_contractor_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date'           => 'date',
            'pairs_produced'      => 'integer',
            'rate_amount'         => 'decimal:2',
            'gross_earnings'      => 'decimal:2',
            'shift_adjustment'    => 'decimal:2',
            'is_locked'           => 'boolean',
            'ghost_flagged_at'    => 'datetime',
            'wage_model_applied'  => 'string',   // CR-001: enum stored as string
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function rateCardEntry(): BelongsTo
    {
        return $this->belongsTo(RateCardEntry::class);
    }

    public function styleSku(): BelongsTo
    {
        return $this->belongsTo(StyleSku::class, 'style_sku_id');
    }

    public function shiftAuthorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shift_adj_authorized_by');
    }

    public function qcRejections(): HasMany
    {
        return $this->hasMany(QcRejection::class);
    }

    public function shiftAdjustment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ShiftAdjustment::class);
    }

    public function ghostWorkerFlag(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(GhostWorkerFlag::class);
    }

    public function billingContractor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'billing_contractor_id');
    }
}
