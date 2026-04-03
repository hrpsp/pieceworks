<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GradeWageRate extends Model
{
    use HasFactory;

    protected $fillable = [
        'rate_card_id',
        'grade',
        'daily_wage_pkr',
    ];

    protected function casts(): array
    {
        return [
            'daily_wage_pkr' => 'decimal:2',
        ];
    }

    public function rateCard(): BelongsTo
    {
        return $this->belongsTo(RateCard::class);
    }
}
