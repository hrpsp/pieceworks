<?php

namespace App\Jobs;

use App\Models\ApiSyncLog;
use App\Services\BataApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBataApiSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function handle(BataApiService $service): void
    {
        $service->poll();
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessBataApiSync failed', ['error' => $e->getMessage()]);

        // Check for consecutive failures - create system alert
        $recent = ApiSyncLog::orderBy('synced_at', 'desc')->take(2)->get();
        $allFailed = $recent->count() >= 2 && $recent->every(fn ($l) => !empty($l->error_message));

        if ($allFailed) {
            Log::critical('Bata API: 2 consecutive sync failures - system alert');
        }
    }
}
