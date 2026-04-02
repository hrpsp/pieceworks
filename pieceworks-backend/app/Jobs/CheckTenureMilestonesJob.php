<?php

namespace App\Jobs;

use App\Models\Worker;
use App\Models\PayrollException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CheckTenureMilestonesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Milestones in days
    private const MILESTONES = [90, 365, 1095, 1825];

    public function handle(): void
    {
        $lookahead = config('pieceworks.tenure_lookahead_days', 30);
        $today = Carbon::today();
        $checked = 0;

        Worker::where('status', 'active')->chunk(100, function ($workers) use ($today, $lookahead, &$checked) {
            foreach ($workers as $worker) {
                $joinDate = Carbon::parse($worker->join_date);
                foreach (self::MILESTONES as $days) {
                    $milestoneDate = $joinDate->copy()->addDays($days);
                    $daysAway = $today->diffInDays($milestoneDate, false);

                    if ($daysAway >= 0 && $daysAway <= $lookahead) {
                        // Check not already alerted
                        $alreadyExists = PayrollException::where('worker_id', $worker->id)
                            ->where('exception_type', 'tenure_milestone')
                            ->where('detail', 'like', "%{$days} day%")
                            ->where('status', 'pending')
                            ->exists();

                        if (!$alreadyExists) {
                            PayrollException::create([
                                'payroll_run_id'  => null,
                                'worker_id'       => $worker->id,
                                'exception_type'  => 'tenure_milestone',
                                'detail'          => "{$worker->name} reaches {$days}-day tenure on {$milestoneDate->toDateString()} ({$daysAway} days away)",
                                'action_required' => $days === 90 ? 'Review IRRA eligibility' : 'Record milestone',
                                'status'          => 'pending',
                            ]);
                            $checked++;
                        }
                    }
                }
            }
        });

        Log::info("CheckTenureMilestonesJob: flagged {$checked} milestone(s)");
    }
}
