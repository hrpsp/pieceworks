<?php

namespace App\Providers;

use App\Models\Advance;
use App\Models\Contractor;
use App\Models\Line;
use App\Models\Loan;
use App\Models\ProductionRecord;
use App\Models\QcRejection;
use App\Models\RateCard;
use App\Models\User;
use App\Models\WeeklyPayrollRun;
use App\Models\Worker;
use App\Observers\AuditObserver;
use App\Observers\QcRejectionObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $audited = [
            Worker::class,
            User::class,
            ProductionRecord::class,
            WeeklyPayrollRun::class,
            Advance::class,
            Loan::class,
            QcRejection::class,
            RateCard::class,
            Contractor::class,
            Line::class,
        ];

        foreach ($audited as $model) {
            $model::observe(AuditObserver::class);
        }

        // Domain-specific observers (run alongside AuditObserver)
        QcRejection::observe(QcRejectionObserver::class);
    }
}
