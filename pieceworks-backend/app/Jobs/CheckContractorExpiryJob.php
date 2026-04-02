<?php

namespace App\Jobs;

use App\Models\Contractor;
use App\Models\PayrollException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CheckContractorExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $threshold = Carbon::now()->addDays(30);
        $flagged = 0;

        Contractor::where('status', 'active')
            ->whereNotNull('contract_end_date')
            ->whereDate('contract_end_date', '<=', $threshold)
            ->chunk(50, function ($contractors) use (&$flagged) {
                foreach ($contractors as $contractor) {
                    $endDate = Carbon::parse($contractor->contract_end_date);
                    $daysLeft = Carbon::today()->diffInDays($endDate, false);

                    $alreadyExists = PayrollException::where('exception_type', 'contractor_expiry')
                        ->where('detail', 'like', "%contractor ID {$contractor->id}%")
                        ->where('status', 'pending')
                        ->exists();

                    if (!$alreadyExists) {
                        PayrollException::create([
                            'payroll_run_id'  => null,
                            'worker_id'       => null,
                            'exception_type'  => 'contractor_expiry',
                            'detail'          => "{$contractor->name} (contractor ID {$contractor->id}) contract expires on {$endDate->toDateString()} ({$daysLeft} days left)",
                            'action_required' => 'Renew or terminate contractor contract',
                            'status'          => 'pending',
                        ]);
                        $flagged++;
                    }
                }
            });

        Log::info("CheckContractorExpiryJob: flagged {$flagged} contractor(s)");
    }
}
