<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Worker extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'contractor_id',
        'name',
        'cnic',
        'photo_path',
        'biometric_id',
        'worker_type',
        'grade',
        'default_shift',
        'default_line_id',
        'training_period',
        'training_end_date',
        'payment_method',
        'payment_number',
        'whatsapp',
        'eobi_number',
        'pessi_number',
        'join_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'training_end_date' => 'date',
            'join_date'         => 'date',
            'training_period'   => 'integer',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function defaultLine(): BelongsTo
    {
        return $this->belongsTo(Line::class, 'default_line_id');
    }

    public function productionRecords(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }

    public function advances(): HasMany
    {
        return $this->hasMany(Advance::class);
    }

    public function weeklyPayrolls(): HasMany
    {
        return $this->hasMany(WorkerWeeklyPayroll::class);
    }

    public function qcRejections(): HasMany
    {
        return $this->hasMany(QcRejection::class);
    }

    public function tenureMilestones(): HasMany
    {
        return $this->hasMany(TenureMilestone::class);
    }

    public function compliance(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(WorkerCompliance::class);
    }

    // ── Accessors ────────────────────────────────────────────────────────────

    public function getIsInTrainingAttribute(): bool
    {
        if (! $this->training_end_date) {
            return false;
        }

        return now()->lessThanOrEqualTo($this->training_end_date);
    }
}
