<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GhostWorkerFlag extends Model
{
    protected $table = 'ghost_worker_flags';

    protected $fillable = [
        'worker_id',
        'production_record_id',
        'work_date',
        'risk_level',
        'biometric_present',
        'production_anomaly',
        'pairs_produced',
        'four_week_avg',
        'std_dev',
        'overridden_at',
        'overridden_by',
        'override_reason',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'work_date'         => 'date',
            'biometric_present' => 'boolean',
            'production_anomaly'=> 'boolean',
            'pairs_produced'    => 'decimal:2',
            'four_week_avg'     => 'decimal:2',
            'std_dev'           => 'decimal:2',
            'overridden_at'     => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function productionRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
