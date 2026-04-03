<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\ShiftAdjustmentController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\GhostWorkerController;
use App\Http\Controllers\Api\AdvanceController;
use App\Http\Controllers\Api\ComplianceController;
use App\Http\Controllers\Api\RateCardController;
use App\Http\Controllers\Api\StyleSkuController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\BataIntegrationController;
use App\Http\Controllers\Api\PayrollStatementController;
use App\Http\Controllers\Api\ContractorPortalController;
use App\Http\Controllers\Api\QcRejectionController;
use App\Http\Controllers\Api\ReportsController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\WorkerController;
use App\Http\Controllers\Api\AdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| PieceWorks API Routes
|--------------------------------------------------------------------------
*/

// Health check (public)
Route::get('/health', function () {
    try {
        \DB::connection()->getPdo();
        $db = 'connected';
    } catch (\Exception) {
        $db = 'error';
    }

    return response()->json([
        'status'  => 'ok',
        'version' => '1.0.0',
        'app'     => config('app.name'),
        'env'     => config('app.env'),
        'db'      => $db,
    ]);
});

// ── Auth ─────────────────────────────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me',     [AuthController::class, 'me']);
    });
});

// ── Protected routes ─────────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // ── Rate Cards ───────────────────────────────────────────────────────────
    // Static named routes MUST appear before the {id} wildcard
    Route::prefix('rate-cards')->group(function () {
        Route::get('active',          [RateCardController::class, 'active'])->middleware('permission:workers.view_all');
        Route::get('history',         [RateCardController::class, 'history'])->middleware('permission:workers.view_all');
        Route::get('/',               [RateCardController::class, 'index'])->middleware('permission:workers.view_all');
        Route::post('/',              [RateCardController::class, 'store'])->middleware('permission:rate_cards.manage');
        Route::get('{id}',            [RateCardController::class, 'show'])->middleware('permission:workers.view_all');
        Route::get('{id}/entries',    [RateCardController::class, 'entries'])->middleware('permission:workers.view_all');
        Route::post('{id}/activate',  [RateCardController::class, 'activate'])->middleware('permission:rate_cards.manage');
    });

    // ── Style SKUs ────────────────────────────────────────────────────────────
    Route::prefix('style-skus')->group(function () {
        Route::get('/',               [StyleSkuController::class, 'index'])->middleware('permission:workers.view_all');
        Route::post('/',              [StyleSkuController::class, 'store'])->middleware('permission:rate_cards.manage');
        Route::patch('{id}/tier',     [StyleSkuController::class, 'updateTier'])->middleware('permission:rate_cards.manage');
    });

    // ── Workers ──────────────────────────────────────────────────────────────
    Route::get('workers',          [WorkerController::class, 'index'])->middleware('permission:workers.view_all');
    Route::post('workers',         [WorkerController::class, 'store'])->middleware('permission:workers.create');
    Route::get('workers/{worker}', [WorkerController::class, 'show'])->middleware('permission:workers.view_all');
    Route::put('workers/{worker}', [WorkerController::class, 'update'])->middleware('permission:workers.edit');
    Route::patch('workers/{worker}', [WorkerController::class, 'update'])->middleware('permission:workers.edit');
    Route::delete('workers/{worker}', [WorkerController::class, 'destroy'])->middleware('permission:workers.edit');

    Route::prefix('workers/{worker}')->middleware('permission:workers.view_all')->group(function () {
        Route::get('production-history', [WorkerController::class, 'productionHistory']);
        Route::get('weekly-summary',     [WorkerController::class, 'weeklySummary']);
        Route::get('advances',           [WorkerController::class, 'advances']);
        Route::get('shift-adjustments',  [WorkerController::class, 'shiftAdjustments']);
        Route::get('loans',              [WorkerController::class, 'loans']);
        Route::get('compliance',         [WorkerController::class, 'compliance']);
    });

    // ── Production ────────────────────────────────────────────────────────────
    Route::prefix('production')->group(function () {
        Route::post('batch',  [ProductionController::class, 'batch'])->middleware('permission:production.enter');
        Route::get('daily',   [ProductionController::class, 'daily'])->middleware('permission:workers.view_all');
        Route::put('{record}',[ProductionController::class, 'update'])->middleware('permission:production.edit_same_day');
        Route::post('backfill', [ProductionController::class, 'backfill'])->middleware('permission:production.backfill');
        Route::get('reconciliation/{date}', [ProductionController::class, 'reconciliation'])->middleware('permission:workers.view_all');
    });

    // ── Payroll ───────────────────────────────────────────────────────────────
    // Static routes before {weekRef} wildcard
    Route::prefix('payroll')->group(function () {
        Route::get('current',  [PayrollController::class, 'current'])->middleware('permission:payroll.run');
        Route::post('calculate',[PayrollController::class, 'calculate'])->middleware('permission:payroll.run');
        Route::patch('exceptions/{exception}/resolve', [PayrollController::class, 'resolveException'])
            ->middleware('permission:exceptions.resolve');

        Route::get('{weekRef}',                    [PayrollController::class, 'show'])->middleware('permission:payroll.run');
        Route::get('{weekRef}/workers',            [PayrollController::class, 'workers'])->middleware('permission:payroll.run');
        Route::post('{weekRef}/lock',              [PayrollController::class, 'lock'])->middleware('permission:payroll.lock');
        Route::post('{weekRef}/release',           [PayrollController::class, 'release'])->middleware('permission:payroll.release');
        Route::post('{weekRef}/reverse',           [PayrollController::class, 'reverse'])->middleware('permission:payroll.reverse');
        Route::get('{weekRef}/exceptions',         [PayrollController::class, 'exceptions'])->middleware('permission:payroll.run');
        Route::get('{weekRef}/reversal-history',   [PayrollController::class, 'reversalHistory'])->middleware('permission:payroll.reverse');
        Route::post('{weekRef}/payedge-handoff',   [PayrollController::class, 'payedgeHandoff'])->middleware('permission:payroll.lock');
        Route::get('{weekRef}/handoff-status',     [PayrollController::class, 'handoffStatus'])->middleware('permission:payroll.run');
    });

    // ── RBAC ──────────────────────────────────────────────────────────────────
    Route::get('roles',                         [RoleController::class, 'index'])->middleware('permission:workers.view_all');
    Route::get('users/{id}/permissions',        [RoleController::class, 'userPermissions'])->middleware('permission:workers.view_all');
    Route::post('users/{id}/roles',             [RoleController::class, 'assignRole'])->middleware('permission:workers.create');
    Route::delete('users/{id}/roles/{roleSlug}',[RoleController::class, 'revokeRole'])->middleware('permission:workers.create');

    // ── Attendance ────────────────────────────────────────────────────────────
    Route::prefix('attendance')->group(function () {
        Route::post('record',          [AttendanceController::class, 'record'])->middleware('permission:production.enter');
        Route::get('daily',            [AttendanceController::class, 'daily'])->middleware('permission:workers.view_all');
        Route::post('biometric-sync',  [AttendanceController::class, 'biometricSync'])->middleware('permission:production.enter');
    });

    // ── Shift Adjustments ─────────────────────────────────────────────────────
    // Static 'pending' route before {id} wildcard
    Route::prefix('shift-adjustments')->group(function () {
        Route::get('pending',          [ShiftAdjustmentController::class, 'pending'])->middleware('permission:workers.view_all');
        Route::get('/',                [ShiftAdjustmentController::class, 'index'])->middleware('permission:workers.view_all');
        Route::post('{id}/confirm',    [ShiftAdjustmentController::class, 'confirm'])->middleware('permission:production.edit_same_day');
    });

    // ── Ghost Worker ──────────────────────────────────────────────────────────
    Route::prefix('ghost-worker')->group(function () {
        Route::get('flags',             [GhostWorkerController::class, 'flags'])->middleware('permission:workers.view_all');
        Route::post('{id}/override',    [GhostWorkerController::class, 'override'])->middleware('permission:ghost_worker.override');
    });

    // ── QC Rejections ─────────────────────────────────────────────────────────
    // Static named routes before {id} wildcard
    Route::prefix('rejections')->group(function () {
        Route::post('/',               [QcRejectionController::class, 'store'])->middleware('permission:rejection.enter');
        Route::get('/',                [QcRejectionController::class, 'index'])->middleware('permission:workers.view_all');
        Route::get('pending-qc',       [QcRejectionController::class, 'pendingQc'])->middleware('permission:rejection.enter');
        Route::get('analysis',         [QcRejectionController::class, 'analysis'])->middleware('permission:reports.view_own');
        Route::patch('{id}/dispute',   [QcRejectionController::class, 'dispute'])->middleware('permission:rejection.dispute');
        Route::patch('{id}/resolve',   [QcRejectionController::class, 'resolve'])->middleware('permission:workers.view_all');
    });

    // ── Advances ──────────────────────────────────────────────────────────────
    Route::prefix('advances')->group(function () {
        Route::post('/',               [AdvanceController::class, 'store'])->middleware('permission:payroll.run');
        Route::get('/',                [AdvanceController::class, 'index'])->middleware('permission:workers.view_all');
        Route::patch('{id}/approve',   [AdvanceController::class, 'approve'])->middleware('permission:payroll.run');
        Route::patch('{id}/reject',    [AdvanceController::class, 'reject'])->middleware('permission:advances.approve');
    });

    // ── Loans ─────────────────────────────────────────────────────────────────
    Route::prefix('loans')->group(function () {
        Route::post('/',                       [LoanController::class, 'store'])->middleware('permission:payroll.run');
        Route::get('/',                        [LoanController::class, 'index'])->middleware('permission:workers.view_all');
        Route::get('{id}',                     [LoanController::class, 'show'])->middleware('permission:workers.view_all');
        Route::post('{id}/early-settle',       [LoanController::class, 'earlySettle'])->middleware('permission:payroll.run');
    });

    // ── Compliance ────────────────────────────────────────────────────────────
    Route::prefix('compliance')->group(function () {
        Route::get('eobi-report',           [ComplianceController::class, 'eobiReport'])->middleware('permission:reports.view_all');
        Route::get('wht-report',            [ComplianceController::class, 'whtReport'])->middleware('permission:reports.view_all');
        Route::get('tenure-milestones',     [ComplianceController::class, 'tenureMilestones'])->middleware('permission:reports.view_all');
        Route::get('missing-registrations', [ComplianceController::class, 'missingRegistrations'])->middleware('permission:reports.view_all');
        Route::post('register-eobi',        [ComplianceController::class, 'registerEobi'])->middleware('permission:workers.edit');
    });

    // ── Payroll Statements & Payment Files ───────────────────────────────────
    Route::prefix('payroll')->group(function () {
        Route::post('{weekRef}/generate-statements', [PayrollStatementController::class, 'generateStatements'])->middleware('permission:payroll.lock');
        Route::post('{weekRef}/send-statements',     [PayrollStatementController::class, 'sendStatements'])->middleware('permission:payroll.lock');
        Route::post('{weekRef}/payment-files',       [PayrollStatementController::class, 'paymentFiles'])->middleware('permission:payroll.lock');
        Route::post('{weekRef}/disputes/{worker_id}',[PayrollStatementController::class, 'dispute'])->middleware('permission:workers.view_all');
    });

    Route::get('workers/{id}/statement/{weekRef}',         [PayrollStatementController::class, 'workerStatement'])->middleware('permission:workers.view_all');
    Route::post('workers/{id}/statement/{weekRef}/generate',     [PayrollStatementController::class, 'generateWorkerStatement'])->middleware('permission:payroll.lock');
    Route::post('workers/{id}/statement/{weekRef}/send-whatsapp',[PayrollStatementController::class, 'sendWorkerWhatsApp'])->middleware('permission:payroll.lock');

    // ── Bata Integration ──────────────────────────────────────────────────────
    // Static named routes before {id} wildcard
    Route::prefix('integration/bata')->group(function () {
        Route::get('status',                              [BataIntegrationController::class, 'status'])->middleware('permission:workers.view_all');
        Route::get('events',                              [BataIntegrationController::class, 'events'])->middleware('permission:workers.view_all');
        Route::post('sync-now',                           [BataIntegrationController::class, 'syncNow'])->middleware('permission:payroll.run');
        Route::get('staging',                             [BataIntegrationController::class, 'staging'])->middleware('permission:workers.view_all');
        Route::post('map-worker',                         [BataIntegrationController::class, 'mapWorker'])->middleware('permission:workers.edit');
        Route::get('unmapped-workers',                    [BataIntegrationController::class, 'unmappedWorkers'])->middleware('permission:workers.view_all');
        Route::post('reconciliation/{date}',              [BataIntegrationController::class, 'reconciliation'])->middleware('permission:reports.view_all');
        Route::patch('staging/{id}/accept-api',           [BataIntegrationController::class, 'acceptApi'])->middleware('permission:payroll.run');
        Route::patch('staging/{id}/accept-manual',        [BataIntegrationController::class, 'acceptManual'])->middleware('permission:payroll.run');
        Route::patch('staging/{id}/hold',                 [BataIntegrationController::class, 'hold'])->middleware('permission:payroll.run');
    });

    // ── Audit Logs ────────────────────────────────────────────────────────────
    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:reports.view_all');

    // ── Reports ───────────────────────────────────────────────────────────────
    // All report endpoints support ?csv=1 for CSV download.
    // EOBI challan also supports ?pdf=1 for a PDF download.
    Route::prefix('reports')->group(function () {
        Route::get('daily-production',       [ReportsController::class, 'dailyProduction'])->middleware('permission:reports.view_own');
        Route::get('worker-efficiency',      [ReportsController::class, 'workerEfficiency'])->middleware('permission:reports.view_own');
        Route::get('wage-cost-per-pair',     [ReportsController::class, 'wageCostPerPair'])->middleware('permission:reports.view_all');
        Route::get('line-productivity',      [ReportsController::class, 'lineProductivity'])->middleware('permission:reports.view_all');
        Route::get('min-wage-compliance',    [ReportsController::class, 'minWageCompliance'])->middleware('permission:reports.view_all');
        Route::get('shift-adjustments',      [ReportsController::class, 'shiftAdjustments'])->middleware('permission:reports.view_all');
        Route::get('ghost-worker',           [ReportsController::class, 'ghostWorker'])->middleware('permission:reports.view_all');
        Route::get('contractor-performance', [ReportsController::class, 'contractorPerformance'])->middleware('permission:reports.view_all');
        Route::get('eobi-challan',           [ReportsController::class, 'eobiChallan'])->middleware('permission:reports.view_all');
        Route::get('tenure-milestones',      [ReportsController::class, 'tenureMilestones'])->middleware('permission:reports.view_all');
        Route::get('rejection-analysis',     [ReportsController::class, 'rejectionAnalysis'])->middleware('permission:reports.view_own');
        Route::get('annual-payroll-summary', [ReportsController::class, 'annualPayrollSummary'])->middleware('permission:reports.view_all');
    });

    // ── Contractors ──────────────────────────────────────────────────────────────
    Route::prefix('contractors')->group(function () {
        Route::get('/',                              [\App\Http\Controllers\Api\ContractorController::class, 'index'])->middleware('permission:workers.view_all');
        Route::post('/',                             [\App\Http\Controllers\Api\ContractorController::class, 'store'])->middleware('permission:workers.create');
        Route::get('{id}',                           [\App\Http\Controllers\Api\ContractorController::class, 'show'])->middleware('permission:workers.view_all');
        Route::put('{id}',                           [\App\Http\Controllers\Api\ContractorController::class, 'update'])->middleware('permission:workers.create');
        Route::delete('{id}',                        [\App\Http\Controllers\Api\ContractorController::class, 'destroy'])->middleware('permission:workers.create');
        Route::get('{id}/workers',                   [\App\Http\Controllers\Api\ContractorController::class, 'workers'])->middleware('permission:workers.view_all');
        Route::get('{id}/settlements',               [\App\Http\Controllers\Api\ContractorController::class, 'settlements'])->middleware('permission:workers.view_all');
        Route::get('{id}/performance-scores',        [\App\Http\Controllers\Api\ContractorController::class, 'performanceScores'])->middleware('permission:workers.view_all');
    });

    // ── Lines ────────────────────────────────────────────────────────────────────
    Route::prefix('lines')->group(function () {
        Route::get('/',      [\App\Http\Controllers\Api\LineController::class, 'index'])->middleware('permission:workers.view_all');
        Route::post('/',     [\App\Http\Controllers\Api\LineController::class, 'store'])->middleware('permission:workers.create');
        Route::get('{id}',   [\App\Http\Controllers\Api\LineController::class, 'show'])->middleware('permission:workers.view_all');
        Route::put('{id}',   [\App\Http\Controllers\Api\LineController::class, 'update'])->middleware('permission:workers.create');
    });

    // ── Admin ─────────────────────────────────────────────────────────────────────
    Route::prefix('admin')->group(function () {
        Route::get('users',               [AdminController::class, 'listUsers'])->middleware('permission:workers.view_all');
        Route::post('users',              [AdminController::class, 'inviteUser'])->middleware('permission:workers.create');
        Route::get('factory-locations',   [AdminController::class, 'listLocations'])->middleware('permission:workers.view_all');
        Route::post('factory-locations',  [AdminController::class, 'createLocation'])->middleware('permission:workers.create');
        Route::get('compliance-config',   [AdminController::class, 'getComplianceConfig'])->middleware('permission:reports.view_all');
        Route::patch('compliance-config', [AdminController::class, 'patchComplianceConfig'])->middleware('permission:workers.create');
    });
});

// ── Contractor Portal ─────────────────────────────────────────────────────────
// Separate group: uses contractor.portal middleware instead of role-based permissions.
// Static 'settlement/history' route declared BEFORE the {weekRef} wildcard.
Route::middleware(['auth:sanctum', 'contractor.portal'])->prefix('contractor')->group(function () {
    Route::get('dashboard',                          [ContractorPortalController::class, 'dashboard']);
    Route::get('workers',                            [ContractorPortalController::class, 'workers']);
    Route::get('settlement/history',                 [ContractorPortalController::class, 'settlementHistory']);
    Route::get('settlement/{weekRef}',               [ContractorPortalController::class, 'settlement']);
    Route::get('rejections',                         [ContractorPortalController::class, 'rejections']);
    Route::post('rejections/{id}/dispute',           [ContractorPortalController::class, 'disputeRejection']);
    Route::get('compliance',                         [ContractorPortalController::class, 'compliance']);
});
