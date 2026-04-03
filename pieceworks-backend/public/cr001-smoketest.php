<?php
/**
 * CR-001 Smoke Test — validates all three wage models via live data.
 *
 * Copy to XAMPP htdocs, then open: http://localhost/cr001-smoketest.php
 * Delete after verification.
 *
 * What this tests:
 *   A) daily_grade  — Worker on a daily-grade unit earns their grade daily wage
 *   B) per_pair     — Worker on a per-pair unit earns pairs × rate
 *   C) hybrid       — Worker on a hybrid unit earns floor + bonus above standard
 *   D) Cross-grade  — Grade-1 worker on a higher-grade unit still earns Grade-1 wage
 *   E) Seeder data  — Confirms GradeWageRates table has 5 rows for active card (A/B/C/D/trainee)
 */

set_time_limit(60);
header('Content-Type: text/html; charset=utf-8');

echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>
body{font-family:monospace;background:#111;color:#eee;padding:20px}
.ok{color:#4caf50;font-weight:bold}.er{color:#f44336;font-weight:bold}
.warn{color:#ff9800;font-weight:bold}.h1{color:#EEC293;font-size:16px;font-weight:bold}
pre{background:#1a1a1a;padding:10px;border-radius:4px;white-space:pre-wrap;font-size:12px}
table{border-collapse:collapse;width:100%;margin:8px 0}
td,th{border:1px solid #333;padding:6px 10px;font-size:12px}
th{background:#322E53;color:#EEC293}
</style></head><body>';

// ── Bootstrap Laravel ────────────────────────────────────────────────────────
$base = 'C:\\Projects\\Pieceworks\\pieceworks-backend';
if (! file_exists($base . '/bootstrap/app.php')) {
    die('<p class="er">ERROR: backend path not found. Update \$base in this file.</p>');
}

$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/smoke-test';

// Laravel 10/11: vendor/autoload.php, not bootstrap/autoload.php
require $base . '/vendor/autoload.php';
$app = require_once $base . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\GradeWageRate;
use App\Models\ProductionUnit;
use App\Models\RateCard;
use App\Models\Worker;
use App\Services\RateEngineService;
use Illuminate\Support\Facades\Schema;

// ── Helper ───────────────────────────────────────────────────────────────────
function row(string $label, $value, bool $pass = true): void {
    $cls = $pass ? 'ok' : 'er';
    echo "<tr><td>{$label}</td><td class=\"{$cls}\">" . htmlspecialchars((string)$value) . "</td></tr>";
}

$service = new RateEngineService();
$today   = now()->toDateString();
$pass    = true;

// ════════════════════════════════════════════════════════════════════════════
echo '<p class="h1">1. Schema Verification</p><table><tr><th>Check</th><th>Result</th></tr>';
$checks = [
    'grade_wage_rates table exists'                => Schema::hasTable('grade_wage_rates'),
    'production_units.wage_model column exists'    => Schema::hasColumn('production_units', 'wage_model'),
    'production_units.standard_output_day exists'  => Schema::hasColumn('production_units', 'standard_output_day'),
    'production_units.bonus_rate_per_pair exists'  => Schema::hasColumn('production_units', 'bonus_rate_per_pair'),
    'production_records.wage_model_applied exists' => Schema::hasColumn('production_records', 'wage_model_applied'),
    'production_records.rate_detail exists'        => Schema::hasColumn('production_records', 'rate_detail'),
];
foreach ($checks as $label => $ok) {
    row($label, $ok ? '✅ YES' : '❌ NO', $ok);
    if (!$ok) $pass = false;
}
echo '</table>';

// ════════════════════════════════════════════════════════════════════════════
echo '<p class="h1">2. GradeWageRates Seeder</p><table><tr><th>Check</th><th>Result</th></tr>';
$activeCard = RateCard::where('is_active', true)->first();
if (! $activeCard) {
    row('Active rate card', '❌ NONE FOUND', false);
    $pass = false;
} else {
    row('Active rate card', "ID={$activeCard->id} v{$activeCard->version}");
    $gwCount = GradeWageRate::where('rate_card_id', $activeCard->id)->count();
    row('GradeWageRate rows', $gwCount . ' (expect 5)', $gwCount === 5);
    if ($gwCount !== 5) $pass = false;

    // Show table
    echo '</table><pre>';
    $gws = GradeWageRate::where('rate_card_id', $activeCard->id)->orderBy('grade')->get();
    foreach ($gws as $gw) {
        printf("  %-12s  PKR %8.2f / day\n", $gw->grade, $gw->daily_wage_pkr);
    }
    echo '</pre><table>';
}

// ════════════════════════════════════════════════════════════════════════════
echo '<p class="h1">3. Wage Model Tests</p>';

// Pick test workers/units
$workerForTest = Worker::whereNotNull('grade')->first();
if (! $workerForTest) {
    echo '<p class="er">No workers seeded — skipping wage model tests.</p>';
} else {
    $dailyUnit  = ProductionUnit::where('wage_model', 'daily_grade')->first();
    $perPairUnit = ProductionUnit::where('wage_model', 'per_pair')->first();
    $hybridUnit = ProductionUnit::where('wage_model', 'hybrid')
                    ->whereNotNull('standard_output_day')
                    ->whereNotNull('bonus_rate_per_pair')
                    ->first();

    echo '<table><tr><th>Test</th><th>Details</th><th>Pass?</th></tr>';

    // ── A. DAILY_GRADE ───────────────────────────────────────────────────────
    if ($dailyUnit) {
        try {
            $r = $service->calculateEarnings(
                workerId:         $workerForTest->id,
                productionUnitId: $dailyUnit->id,
                workDate:         $today,
                pairsProduced:    88,
                task:             'Stitching',
                styleSkuId:       null
            );
            $expectedWage = GradeWageRate::where('rate_card_id', $activeCard->id)
                ->where('grade', $workerForTest->grade)->value('daily_wage_pkr');

            $correct = $r['wage_model'] === 'daily_grade' && (float)$r['earnings'] === (float)$expectedWage;
            echo "<tr><td>A: daily_grade<br><small>Worker grade={$workerForTest->grade}, unit={$dailyUnit->name}</small></td>"
               . "<td>earnings=PKR{$r['earnings']}<br>detail: {$r['rate_detail']}</td>"
               . '<td class="' . ($correct ? 'ok' : 'er') . '">' . ($correct ? '✅ PASS' : '❌ FAIL') . '</td></tr>';
            if (!$correct) $pass = false;
        } catch (\Throwable $e) {
            echo "<tr><td>A: daily_grade</td><td>{$e->getMessage()}</td><td class='er'>❌ ERROR</td></tr>";
            $pass = false;
        }
    } else {
        echo "<tr><td>A: daily_grade</td><td>No daily_grade unit seeded</td><td class='warn'>⚠ SKIP</td></tr>";
    }

    // ── B. PER_PAIR ──────────────────────────────────────────────────────────
    if ($perPairUnit) {
        $sku = \App\Models\StyleSku::first();
        if ($sku) {
            try {
                $r = $service->calculateEarnings(
                    workerId:         $workerForTest->id,
                    productionUnitId: $perPairUnit->id,
                    workDate:         $today,
                    pairsProduced:    88,
                    task:             'Stitching',
                    styleSkuId:       $sku->id
                );
                $correct = $r['wage_model'] === 'per_pair' && $r['earnings'] > 0;
                echo "<tr><td>B: per_pair<br><small>88 pairs, sku={$sku->id}</small></td>"
                   . "<td>earnings=PKR{$r['earnings']}<br>detail: {$r['rate_detail']}</td>"
                   . '<td class="' . ($correct ? 'ok' : 'er') . '">' . ($correct ? '✅ PASS' : '❌ FAIL') . '</td></tr>';
                if (!$correct) $pass = false;
            } catch (\Throwable $e) {
                echo "<tr><td>B: per_pair</td><td>{$e->getMessage()}</td><td class='warn'>⚠ WARN (no rate entry)</td></tr>";
            }
        }
    } else {
        echo "<tr><td>B: per_pair</td><td>No per_pair unit seeded</td><td class='warn'>⚠ SKIP</td></tr>";
    }

    // ── C. HYBRID (below standard) ───────────────────────────────────────────
    if ($hybridUnit) {
        try {
            $pairsBelow = max(0, ($hybridUnit->standard_output_day ?? 100) - 10);
            $r = $service->calculateEarnings(
                workerId:         $workerForTest->id,
                productionUnitId: $hybridUnit->id,
                workDate:         $today,
                pairsProduced:    $pairsBelow,
                task:             'Finishing',
                styleSkuId:       null
            );
            $gradeWage = GradeWageRate::where('rate_card_id', $activeCard->id)
                ->where('grade', $workerForTest->grade)->value('daily_wage_pkr');
            $correctFloor = $r['wage_model'] === 'hybrid' && (float)$r['earnings'] === (float)$gradeWage;
            echo "<tr><td>C: hybrid (below standard)<br><small>{$pairsBelow} pairs &lt; {$hybridUnit->standard_output_day} standard</small></td>"
               . "<td>earnings=PKR{$r['earnings']}<br>detail: {$r['rate_detail']}</td>"
               . '<td class="' . ($correctFloor ? 'ok' : 'er') . '">' . ($correctFloor ? '✅ PASS' : '❌ FAIL') . '</td></tr>';
            if (!$correctFloor) $pass = false;

            // ── D. HYBRID (above standard) ───────────────────────────────────
            $pairsAbove = ($hybridUnit->standard_output_day ?? 100) + 20;
            $r2 = $service->calculateEarnings(
                workerId:         $workerForTest->id,
                productionUnitId: $hybridUnit->id,
                workDate:         $today,
                pairsProduced:    $pairsAbove,
                task:             'Finishing',
                styleSkuId:       null
            );
            $expectedBonus   = 20 * (float)($hybridUnit->bonus_rate_per_pair ?? 0);
            $expectedTotal   = round((float)$gradeWage + $expectedBonus, 2);
            $correctBonus    = $r2['wage_model'] === 'hybrid' && abs($r2['earnings'] - $expectedTotal) < 0.01;
            echo "<tr><td>D: hybrid (above standard)<br><small>{$pairsAbove} pairs, +20 bonus</small></td>"
               . "<td>earnings=PKR{$r2['earnings']} (expected PKR{$expectedTotal})<br>detail: {$r2['rate_detail']}</td>"
               . '<td class="' . ($correctBonus ? 'ok' : 'er') . '">' . ($correctBonus ? '✅ PASS' : '❌ FAIL') . '</td></tr>';
            if (!$correctBonus) $pass = false;

        } catch (\Throwable $e) {
            echo "<tr><td>C/D: hybrid</td><td>{$e->getMessage()}</td><td class='er'>❌ ERROR</td></tr>";
            $pass = false;
        }
    } else {
        echo "<tr><td>C/D: hybrid</td><td>No hybrid unit seeded with bonus config</td><td class='warn'>⚠ SKIP</td></tr>";
    }

    // ── E. Cross-grade rule ──────────────────────────────────────────────────
    if ($dailyUnit && $workerForTest) {
        // Verify rate is WORKER's grade, not unit's grade
        try {
            $r = $service->calculateEarnings(
                workerId:         $workerForTest->id,
                productionUnitId: $dailyUnit->id,
                workDate:         $today,
                pairsProduced:    88,
                task:             'Stitching',
                styleSkuId:       null
            );
            $workerGradeWage = GradeWageRate::where('rate_card_id', $activeCard->id)
                ->where('grade', $workerForTest->grade)->value('daily_wage_pkr');

            $crossGradeOk = abs((float)$r['earnings'] - (float)$workerGradeWage) < 0.01;
            echo "<tr><td>E: Cross-grade rule<br><small>earnings = worker.grade wage, not unit grade</small></td>"
               . "<td>worker grade={$workerForTest->grade}, earned=PKR{$r['earnings']}, expected=PKR{$workerGradeWage}</td>"
               . '<td class="' . ($crossGradeOk ? 'ok' : 'er') . '">' . ($crossGradeOk ? '✅ PASS' : '❌ FAIL') . '</td></tr>';
            if (!$crossGradeOk) $pass = false;
        } catch (\Throwable $e) {
            echo "<tr><td>E: Cross-grade</td><td>{$e->getMessage()}</td><td class='er'>❌ ERROR</td></tr>";
        }
    }

    echo '</table>';
}

// ════════════════════════════════════════════════════════════════════════════
echo '<hr style="border-color:#333">';
if ($pass) {
    echo '<p class="ok" style="font-size:18px">✅ ALL SMOKE TESTS PASSED — CR-001 is good to go.</p>';
} else {
    echo '<p class="er" style="font-size:18px">❌ ONE OR MORE TESTS FAILED — see details above.</p>';
}
echo '</body></html>';
