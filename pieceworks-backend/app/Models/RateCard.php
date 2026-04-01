<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RateCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'version',
        'effective_date',
        'approved_by',
        'is_active',
        'training_rate_pct',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'effective_date'    => 'date',
            'is_active'         => 'boolean',
            'training_rate_pct' => 'decimal:2',
        ];
    }

    /**
     * Derived status for display purposes.
     * 'active'     → currently live
     * 'scheduled'  → effective_date is in the future, awaiting activation
     * 'superseded' → inactive, effective_date has passed
     */
    public function getStatusAttribute(): string
    {
        if ($this->is_active) {
            return 'active';
        }
        if ($this->effective_date->isFuture()) {
            return 'scheduled';
        }
        return 'superseded';
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RateCardEntry::class);
    }
}
