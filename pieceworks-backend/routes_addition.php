<?php
/**
 * ════════════════════════════════════════════════════════════════════════════════
 *  Sprint 13 — Backend Patch Instructions
 *  Apply to: C:\Projects\Pieceworks\pieceworks-backend
 * ════════════════════════════════════════════════════════════════════════════════
 *
 * STEP 1 — Copy the controller file:
 *   FROM: sprint13-backend-patch\app\Http\Controllers\Api\AdminController.php
 *   TO:   C:\Projects\Pieceworks\pieceworks-backend\app\Http\Controllers\Api\AdminController.php
 *
 * STEP 2 — Edit routes\api.php (two changes):
 *
 *   Change A — Add this import at the TOP (after the other `use` statements, around line 21):
 *     use App\Http\Controllers\Api\AdminController;
 *
 *   Change B — Add this route group BEFORE the closing `});` of the main auth:sanctum group
 *              (just before the "Contractor Portal" comment, around line 252):
 */

    // ── Admin (Sprint 13) ────────────────────────────────────────────────────
    Route::prefix('admin')->group(function () {
        Route::get('users',               [AdminController::class, 'listUsers']);
        Route::post('users',              [AdminController::class, 'inviteUser']);
        Route::get('factory-locations',   [AdminController::class, 'listLocations']);
        Route::post('factory-locations',  [AdminController::class, 'createLocation']);
        Route::get('compliance-config',   [AdminController::class, 'getComplianceConfig']);
        Route::patch('compliance-config', [AdminController::class, 'patchComplianceConfig']);
    });

/**
 * ── What it looks like after applying Change B ──────────────────────────────
 *
 *     Route::put('{id}', [LineController::class, 'update'])->middleware(...);
 *   });
 *
 *   // ── Admin (Sprint 13) ────────────────────────────────────────────
 *   Route::prefix('admin')->group(function () {
 *       Route::get('users',               [AdminController::class, 'listUsers']);
 *       Route::post('users',              [AdminController::class, 'inviteUser']);
 *       Route::get('factory-locations',   [AdminController::class, 'listLocations']);
 *       Route::post('factory-locations',  [AdminController::class, 'createLocation']);
 *       Route::get('compliance-config',   [AdminController::class, 'getComplianceConfig']);
 *       Route::patch('compliance-config', [AdminController::class, 'patchComplianceConfig']);
 *   });
 * });                   <-- this is the closing }) of auth:sanctum group (keep this)
 *
 * // ── Contractor Portal ──────────────────────────────────────────────
 *
 * ── After applying ──────────────────────────────────────────────────────────
 * No artisan commands needed. Laravel auto-reloads route changes.
 * Just refresh the Settings page after copying the files.
 *
 * Endpoints unlocked:
 *   GET  /api/admin/users               → Settings > Users tab (list + Invite modal)
 *   POST /api/admin/users               → Invite User form submit
 *   GET  /api/admin/factory-locations   → Settings > Locations tab (list + Add modal)
 *   POST /api/admin/factory-locations   → Add Location form submit
 *   GET  /api/admin/compliance-config   → Settings > Compliance tab (rate editor)
 *   PATCH /api/admin/compliance-config  → Save compliance rates
 * ════════════════════════════════════════════════════════════════════════════════
 */
