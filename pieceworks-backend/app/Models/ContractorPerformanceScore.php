<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractorPerformanceScore extends Model
{
    protected $table = 'contractor_performance_scores';

    protected $fillable = [
        'contractor_id',
        'week_ref',
        'delivery_score',
        'rejection_rate',
        'compliance_score',
        'min_wage_shortfall_count',
        'ghost_worker_flags',
        'composite_score',
    ];

    protected function casts(): array
    {
        return [
            'delivery_score'           => 'decimal:2',
            'rejection_rate'           => 'decimal:4',
            'compliance_score'         => 'decimal:2',
            'composite_score'          => 'decimal:2',
            'min_wage_shortfall_count' => 'integer',
            'ghost_worker_flags'       => 'integer',
        ];
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }
}
