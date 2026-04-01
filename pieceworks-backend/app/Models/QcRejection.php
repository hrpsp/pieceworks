<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcRejection extends Model
{
    use HasFactory;

    protected $fillable = [
        'production_record_id',
        'worker_id',
        'work_date',
        'pairs_rejected',
        'defect_type',
        'penalty_mode',
        'penalty_type',
        'penalty_amount',
        'pairs_deducted',
        'status',
        'disputed_at',
        'disputed_by',
        'dispute_reason',
        'resolved_by',
        'resolution',
        'resolution_notes',
        'resolved_at',
        'credit_created',
    ];

    protected function casts(): array
    {
        return [
            'work_date'      => 'date',
            'pairs_rejected' => 'integer',
            'pairs_deducted' => 'integer',
            'penalty_amount' => 'decimal:2',
            'disputed_at'    => 'datetime',
            'resolved_at'    => 'datetime',
            'credit_created' => 'boolean',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function productionRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function disputer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disputed_by');
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class, 'reference_id')
            ->where('reference_type', self::class);
    }
}
