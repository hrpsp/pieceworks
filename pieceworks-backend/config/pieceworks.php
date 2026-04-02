<?php

/**
 * PieceWorks — application configuration
 *
 * Covers: payroll rules, shift times, compliance thresholds,
 * advance settings, integration parameters, and system defaults.
 *
 * Previously: config/payroll.php — renamed to config/pieceworks.php
 * to reflect the broader scope of this module.
 *
 * All values can be overridden via the corresponding .env keys.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Weekly Wage (PKR)
    |--------------------------------------------------------------------------
    | Federal minimum wage PKR 37,000/month ÷ 4.333 weeks ≈ 8,540
    */
    'minimum_weekly_wage' => env('PAYROLL_MIN_WEEKLY_WAGE', 8_545.00),

    /*
    |--------------------------------------------------------------------------
    | Shift Allowances (PKR per week)
    |--------------------------------------------------------------------------
    | shift_allowance       – standard attendance/transport allowance
    | night_shift_allowance – premium for workers scheduled on night shift
    */
    'shift_allowance_per_worker' => env('PAYROLL_SHIFT_ALLOWANCE', 500.00),
    'night_shift_allowance'      => env('PAYROLL_NIGHT_ALLOWANCE', 750.00),

    /*
    |--------------------------------------------------------------------------
    | Overtime
    |--------------------------------------------------------------------------
    | weekly_regular_hours – hours before OT kicks in (6 days × 8h = 48)
    | shift_hours          – standard hours per shift
    | ot_multiplier        – premium factor (1.0 = 100% extra on top of base)
    | min_gap_hours        – rest gap below which a shift is flagged as OT
    */
    'weekly_regular_hours' => 48,
    'shift_hours'          => 8,
    'ot_multiplier'        => 1.0,     // 2× total = base + 1× premium
    'min_gap_hours'        => 8,

    /*
    |--------------------------------------------------------------------------
    | Call-in Minimum Guarantee
    |--------------------------------------------------------------------------
    | If a worker is called in with fewer than callin_threshold_hours rest,
    | they are guaranteed at least callin_min_hours × (min_wage / 48) PKR.
    */
    'callin_threshold_hours' => 4,
    'callin_min_hours'       => 4,

    /*
    |--------------------------------------------------------------------------
    | QC Rejection
    |--------------------------------------------------------------------------
    | rejection_penalty_per_pair – flat PKR deducted per rejected pair
    |   for the flat_penalty mode.
    */
    'rejection_penalty_per_pair' => env('PAYROLL_REJECTION_PENALTY_PER_PAIR', 5.00),

    /*
    |--------------------------------------------------------------------------
    | Advance Settings
    |--------------------------------------------------------------------------
    | auto_approve_limit  – advances up to this PKR amount are auto-approved
    | max_carry_weeks     – weeks after which a carry-forward triggers HR flag
    */
    'advance_auto_approve_limit' => env('PAYROLL_ADVANCE_AUTO_LIMIT', 2_000.00),
    'advance_max_carry_weeks'    => env('PAYROLL_ADVANCE_MAX_CARRY', 2),

    /*
    |--------------------------------------------------------------------------
    | Carry-Forward Alert Threshold
    |--------------------------------------------------------------------------
    | If carry_forward_amount > carry_alert_multiplier × 3-week avg earnings,
    | a PayrollException is raised for HR review.
    */
    'carry_alert_multiplier' => 3,

    /*
    |--------------------------------------------------------------------------
    | Statutory Compliance
    |--------------------------------------------------------------------------
    | default_province        – factory province for minimum wage lookup
    | wht_threshold           – annual income (PKR) above which WHT applies
    | tenure_lookahead_days   – days ahead to surface approaching milestones
    | minimum_wages_by_province – 2026 monthly minimums (PKR)
    */
    'default_province'      => env('PAYROLL_PROVINCE', 'punjab'),
    'wht_threshold'         => env('PAYROLL_WHT_THRESHOLD', 600_000.00),
    'tenure_lookahead_days' => 30,

    'minimum_wages_by_province' => [
        'punjab'      => 37_000.00,
        'sindh'       => 37_000.00,
        'kpk'         => 36_000.00,
        'balochistan' => 32_000.00,
        'federal'     => 37_000.00,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shift Start / End Times (24-hour)
    |--------------------------------------------------------------------------
    */
    'shift_times' => [
        'morning'   => ['start' => '07:00', 'end' => '15:00'],
        'afternoon' => ['start' => '15:00', 'end' => '23:00'],
        'night'     => ['start' => '23:00', 'end' => '07:00'], // ends next day
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment & Integration Settings
    |--------------------------------------------------------------------------
    */
    'jazzcash_daily_limit'         => env('PAYROLL_JAZZCASH_DAILY_LIMIT', 25_000.00),
    'dispute_window_hours'         => env('PAYROLL_DISPUTE_WINDOW_HOURS', 12),
    'ghost_worker_anomaly_threshold' => env('PAYROLL_GHOST_ANOMALY_MULTIPLIER', 2.0),
    'bata_api_poll_interval_minutes' => env('BATA_API_POLL_INTERVAL', 30),
];
