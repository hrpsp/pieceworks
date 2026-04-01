<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    protected $table = 'public_holidays';

    protected $fillable = ['name', 'holiday_date', 'province', 'is_active'];

    protected function casts(): array
    {
        return [
            'holiday_date' => 'date',
            'is_active'    => 'boolean',
        ];
    }

    /**
     * Check whether a given date is a public holiday.
     * Matches 'all', 'federal', and optionally a specific province.
     */
    public static function isHoliday(string $date, ?string $province = null): bool
    {
        return self::where('holiday_date', $date)
            ->where('is_active', true)
            ->where(function ($q) use ($province) {
                $q->whereIn('province', ['all', 'federal']);
                if ($province) {
                    $q->orWhere('province', strtolower($province));
                }
            })
            ->exists();
    }
}
