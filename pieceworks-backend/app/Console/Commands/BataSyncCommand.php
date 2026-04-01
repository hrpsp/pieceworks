<?php

namespace App\Console\Commands;

use App\Models\ApiSyncLog;
use App\Services\BataApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BataSyncCommand extends Command
{
    protected $signature   = 'bata:sync';
    protected $description = 'Poll the Bata Supply Chain API and stage incoming production records.';

    // Number of consecutive failures (within the look-back window) that
    // triggers a CRITICAL alert so the payroll manager can investigate.
    private const FAILURE_THRESHOLD    = 2;
    private const FAILURE_LOOKBACK_HRS = 2;

    public function handle(BataApiService $bataService): int
    {
        $this->info('[bata:sync] Starting poll at ' . now()->toDateTimeString());

        try {
            $bataService->poll();
            $this->info('[bata:sync] Poll completed successfully.');

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('[bata:sync] Poll failed: ' . $e->getMessage());

            // Count how many consecutive failures have occurred in the look-back window
            $consecutiveFailures = ApiSyncLog::where('sync_type', 'bata_poll')
                ->whereNotNull('error_message')
                ->where('synced_at', '>=', now()->subHours(self::FAILURE_LOOKBACK_HRS))
                ->count();

            if ($consecutiveFailures >= self::FAILURE_THRESHOLD) {
                $this->raiseConsecutiveFailureAlert($consecutiveFailures, $e->getMessage());
            }

            return self::FAILURE;
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function raiseConsecutiveFailureAlert(int $count, string $lastError): void
    {
        $message = "[SYNC_FAILED] Bata API has failed {$count} time(s) in the last "
                 . self::FAILURE_LOOKBACK_HRS . ' hours. '
                 . 'Payroll manager action required. Last error: ' . $lastError;

        Log::critical($message, [
            'consecutive_failures' => $count,
            'lookback_hours'       => self::FAILURE_LOOKBACK_HRS,
            'last_error'           => $lastError,
        ]);

        $this->error($message);

        // Write a dedicated critical log entry so the status endpoint surfaces it
        ApiSyncLog::create([
            'sync_type'        => 'bata_poll',
            'records_received' => 0,
            'records_clean'    => 0,
            'records_held'     => 0,
            'error_message'    => "[CRITICAL] {$message}",
            'synced_at'        => now(),
        ]);

        // Dispatch to the exception queue so any registered notification listeners
        // (email, Slack, etc.) can pick this up from the queue worker.
        try {
            dispatch(fn () => Log::channel('slack')->critical($message))
                ->onQueue('exceptions')
                ->afterCommit();
        } catch (\Throwable) {
            // Queue may not be configured in all environments; swallow silently.
        }
    }
}
