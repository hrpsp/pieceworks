<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductionUnit extends Model
{
    use HasFactory, SoftDeletes;

    // ── Wage model constants (CR-001) ────────────────────────────────────────

    const WAGE_MODEL_DAILY_GRADE = 'daily_grade';
    const WAGE_MODEL_PER_PAIR    = 'per_pair';
    const WAGE_MODEL_HYBRID      = 'hybrid';

    // ── Mass-assignable fields ───────────────────────────────────────────────

    protected $fillable = [
        'name',
        'line_id',
        'operation',
        'supervisor_id',
        'default_contractor_id',
        'capacity_workers',
        'status',
        'wage_model',               // CR-001
        'standard_output_day',      // CR-001
        'bonus_rate_per_pair',      // CR-001
    ];

    // ── Casts ────────────────────────────────────────────────────────────────

    protected function casts(): array
    {
        return [
            'bonus_rate_per_pair' => 'decimal:2',
            'standard_output_day' => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function defaultContractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class, 'default_contractor_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    public function productionRecords(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }
}
