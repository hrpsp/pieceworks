<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAdjustment extends Model
{
    protected $table = 'shift_adjustments';

    protected $fillable = [
        'production_record_id',
        'worker_id',
        'work_date',
        'scheduled_shift',
        'actual_shift',
        'line_id',
        'hours_gap_from_last_shift',
        'overtime_flagged',
        'authorized_by',
        'reason',
        'reason_text',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date'                 => 'date',
            'hours_gap_from_last_shift' => 'decimal:2',
            'overtime_flagged'          => 'boolean',
            'confirmed_at'              => 'datetime',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function productionRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->confirmed_at === null;
    }
}
