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
    | ot_threshold_regular   – weekly hours before OT kicks in (Bata standard: 45h)
    | ot_threshold_watchward – Watch & Ward workers have a higher threshold (48h)
    | shift_hours            – standard hours per shift
    | ot_multiplier          – premium factor (1.0 = 100% extra on top of base)
    | min_gap_hours          – rest gap below which a shift is flagged as OT
    | night_ot_eligible_shifts – shifts that attract night-OT premium
    | ot_categories          – the three OT buckets tracked per worker per week
    |
    | Note: weekly_regular_hours retained as an alias for ot_threshold_regular
    | for backward compatibility with existing code referencing it.
    */
    'weekly_regular_hours'    => 45,        // updated from 48 — Bata 5-day × 9h = 45
    'ot_threshold_regular'    => 45,        // all workers except Watch & Ward
    'ot_threshold_watchward'  => 48,        // Watch & Ward (GB shift) extended threshold
    'shift_hours'             => 9,         // GA/GB = 9h net; E1/E2/E3 = 8h net
    'ot_multiplier'           => 1.0,       // 2× total = base + 1× premium
    'min_gap_hours'           => 8,
    'night_ot_eligible_shifts' => ['E2', 'E3', 'GB'],
    'ot_categories'           => ['regular', 'night', 'extra'],

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
    | Shifts Master (Bata shift codes — effective after CR-004 migration)
    |--------------------------------------------------------------------------
    | Keys match the new enum: GA, E1, E2, E3, GB
    |
    | night_ot_eligible – workers on these shifts earn the night-OT premium
    | saturday_hours    – hours clocked on Saturday (proportional day)
    | weekly_hours      – contractual hours: regular days × shift_hours + saturday_hours
    */
    'shifts' => [
        'GA' => [
            'label'              => 'General Day',
            'start'              => '07:00',
            'end'                => '17:00',
            'hours'              => 9,
            'days_per_week'      => 5,
            'saturday_hours'     => 0,
            'weekly_hours'       => 45,
            'break_minutes'      => 60,
            'night_ot_eligible'  => false,
        ],
        'E1' => [
            'label'              => 'Early Morning',
            'start'              => '06:00',
            'end'                => '14:00',
            'hours'              => 8,
            'days_per_week'      => 5.5,
            'saturday_hours'     => 5,
            'weekly_hours'       => 45,
            'break_minutes'      => 0,
            'night_ot_eligible'  => false,
        ],
        'E2' => [
            'label'              => 'Afternoon',
            'start'              => '14:00',
            'end'                => '22:00',
            'hours'              => 8,
            'days_per_week'      => 5.5,
            'saturday_hours'     => 5,
            'weekly_hours'       => 45,
            'break_minutes'      => 0,
            'night_ot_eligible'  => true,
        ],
        'E3' => [
            'label'              => 'Night',
            'start'              => '22:00',
            'end'                => '06:00',
            'hours'              => 8,
            'days_per_week'      => 5.5,
            'saturday_hours'     => 5,
            'weekly_hours'       => 45,
            'break_minutes'      => 0,
            'night_ot_eligible'  => true,
        ],
        'GB' => [
            'label'              => 'Late Evening / Watch & Ward',
            'start'              => '17:00',
            'end'                => '03:00',
            'hours'              => 9,
            'days_per_week'      => 5,
            'saturday_hours'     => 0,
            'weekly_hours'       => 45,
            'break_minutes'      => 60,
            'night_ot_eligible'  => true,
        ],
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

    /*
    |--------------------------------------------------------------------------
    | Wage Models
    |--------------------------------------------------------------------------
    | Defines the three supported wage calculation models, their human-readable
    | labels, descriptions, and the data prerequisites each model requires
    | before earnings can be calculated.
    */
    'wage_models' => [
        'daily_grade' => [
            'label'       => 'Daily Grade Wage',
            'description' => 'Fixed daily wage based on worker grade. Pairs tracked for productivity only.',
            'requires'    => ['grade_wage_rates'],
        ],
        'per_pair' => [
            'label'       => 'Per Pair Rate',
            'description' => 'Pay per pair produced based on task, tier, and grade.',
            'requires'    => ['rate_card_entries'],
        ],
        'hybrid' => [
            'label'       => 'Hybrid (Floor + Bonus)',
            'description' => 'Guaranteed daily minimum plus bonus per pair above standard output.',
            'requires'    => ['grade_wage_rates', 'standard_output_day', 'bonus_rate_per_pair'],
        ],
    ],
];
