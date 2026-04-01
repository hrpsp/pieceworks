<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenureMilestone extends Model
{
    protected $table = 'tenure_milestones';

    protected $fillable = [
        'worker_id',
        'milestone_days',
        'reached_at',
        'alerted',
        'action_taken',
    ];

    protected function casts(): array
    {
        return [
            'reached_at' => 'date',
            'alerted'    => 'boolean',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
