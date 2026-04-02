<?php

namespace App\Jobs;

use App\Models\WeeklyPayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TriggerWeeklyPayrollRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Calculate next week (Mon-Sat)
        $monday = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $saturday = $monday->copy()->addDays(5);
        $weekRef = $monday->format('Y') . '-W' . str_pad($monday->weekOfYear, 2, '0', STR_PAD_LEFT);

        $existing = WeeklyPayrollRun::where('week_ref', $weekRef)->first();
        if (!$existing) {
            WeeklyPayrollRun::create([
                'week_ref'        => $weekRef,
                'start_date'      => $monday->toDateString(),
                'end_date'        => $saturday->toDateString(),
                'status'          => 'open',
                'total_gross'     => 0,
                'total_topups'    => 0,
                'total_deductions' => 0,
                'total_net'       => 0,
            ]);
            Log::info("Created payroll run for week {$weekRef}");
        }

        CalculateWeeklyPayroll::dispatch($weekRef);
    }
}
