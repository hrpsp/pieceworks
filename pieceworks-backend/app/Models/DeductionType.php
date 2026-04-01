<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeductionType extends Model
{
    protected $table = 'deduction_types';

    protected $fillable = [
        'name',
        'code',
        'calculation_type',
        'requires_approval',
        'max_per_week',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'max_per_week'      => 'decimal:2',
            'is_active'         => 'boolean',
        ];
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(Deduction::class);
    }
}
