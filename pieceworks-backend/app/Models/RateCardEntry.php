<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RateCardEntry extends Model
{
    use HasFactory;

    protected $fillable = [
        'rate_card_id',
        'task',
        'complexity_tier',
        'worker_grade',
        'rate_pkr',
    ];

    protected function casts(): array
    {
        return [
            'rate_pkr' => 'decimal:2',
        ];
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }
}
