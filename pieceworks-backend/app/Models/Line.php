<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Line extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'factory_location',
        'default_shift',
        'supervisor_id',
        'default_contractor_id',
        'capacity_pairs_day',
        'status',
    ];

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
        return $this->hasMany(Worker::class, 'default_line_id');
    }

    public function productionRecords(): HasMany
    {
        return $this->hasMany(ProductionRecord::class);
    }
}
