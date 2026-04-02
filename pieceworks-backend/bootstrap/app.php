<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Bearer-token API: only ForceJsonResponse needed.
        // EnsureFrontendRequestsAreStateful is intentionally excluded —
        // it adds CSRF protection suited to cookie-based SPAs, which conflicts
        // with this app's stateless Bearer-token authentication.
        $middleware->api(prepend: [
            \App\Http\Middleware\ForceJsonResponse::class,
        ]);

        $middleware->alias([
            'verified'          => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'permission'        => \App\Http\Middleware\CheckPermission::class,
            'contractor.portal' => \App\Http\Middleware\ContractorPortalMiddleware::class,
            'force.json'        => \App\Http\Middleware\ForceJsonResponse::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        // ── Every 30 minutes ────────────────────────────────────────────────
        $schedule->command('bata:sync')->everyThirtyMinutes();

        // ── Daily at 09:00 ────────────────────────────────────────────────────
        $schedule->job(new \App\Jobs\CheckTenureMilestonesJob)->dailyAt('09:00');
        $schedule->job(new \App\Jobs\CheckContractorExpiryJob)->dailyAt('09:00');

        // ── Weekly payroll automation (Sunday night) ──────────────────────────
        // 22:00 — create the new payroll run and trigger calculation
        $schedule->job(new \App\Jobs\TriggerWeeklyPayrollRun)->weekly()->sundays()->at('22:00');
        // 22:15 — generate PDF/text statements for all workers in the locked run
        $schedule->job(new \App\Jobs\GenerateAllStatementsJob)->weekly()->sundays()->at('22:15');
        // 22:45 — send generated statements via WhatsApp
        $schedule->job(new \App\Jobs\SendAllStatementsJob)->weekly()->sundays()->at('22:45');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Return JSON for unauthenticated API requests instead of a redirect
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Unauthenticated',
                    'data'    => null,
                ], 401);
            }
        });

        // Return JSON for authorization exceptions (403 Forbidden)
        $exceptions->render(function (\Illuminate\Auth\AuthorizationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Forbidden',
                    'data'    => null,
                ], 403);
            }
        });

        // Return JSON for validation exceptions (422)
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Validation failed',
                    'data'    => null,
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // Return JSON for model-not-found (route model binding misses)
        $exceptions->render(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Resource not found',
                    'data'    => null,
                ], 404);
            }
        });
    })->create();
