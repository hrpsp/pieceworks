<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Deduction extends Model
{
    protected $table = 'deductions';

    protected $fillable = [
        'worker_id',
        'payroll_run_id',
        'deduction_type_id',
        'amount',
        'reference_id',
        'reference_type',
        'week_ref',
        'carry_from_week',
        'status',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(WeeklyPayrollRun::class);
    }

    public function deductionType(): BelongsTo
    {
        return $this->belongsTo(DeductionType::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Resolve the deduction_type_id for a given code (e.g. 'rejection_penalty').
     * Caches in-memory to avoid repeated DB hits per request.
     */
    public static function typeId(string $code): int
    {
        static $cache = [];
        if (! isset($cache[$code])) {
            $cache[$code] = \DB::table('deduction_types')
                ->where('code', $code)
                ->value('id');
        }
        if (! $cache[$code]) {
            throw new \RuntimeException("Deduction type '{$code}' not found. Run seeders.");
        }
        return $cache[$code];
    }
}
