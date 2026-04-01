<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contractor extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ntn_cnic',
        'contact_person',
        'phone',
        'contract_start',
        'contract_end',
        'payment_cycle',
        'bank_account',
        'portal_access',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'contract_start' => 'date',
            'contract_end'   => 'date',
            'portal_access'  => 'boolean',
        ];
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(Line::class, 'default_contractor_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(ContractorSettlement::class);
    }

    public function performanceScores(): HasMany
    {
        return $this->hasMany(ContractorPerformanceScore::class);
    }
}
