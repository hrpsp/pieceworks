<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LineTarget extends Model
{
    protected $table = 'line_targets';

    protected $fillable = [
        'line_id',
        'target_date',
        'shift',
        'target_pairs',
    ];

    protected function casts(): array
    {
        return [
            'target_date'  => 'date',
            'target_pairs' => 'integer',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }
}
