<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // --- Permissions ---
        $permissions = [
            // Workers
            ['name' => 'Create Workers',         'slug' => 'workers.create',           'module' => 'workers'],
            ['name' => 'Edit Workers',            'slug' => 'workers.edit',             'module' => 'workers'],
            ['name' => 'View All Workers',        'slug' => 'workers.view_all',         'module' => 'workers'],
            // Production
            ['name' => 'Enter Production',        'slug' => 'production.enter',         'module' => 'production'],
            ['name' => 'Backfill Production',     'slug' => 'production.backfill',      'module' => 'production'],
            ['name' => 'Edit Same-Day Production','slug' => 'production.edit_same_day', 'module' => 'production'],
            // Payroll
            ['name' => 'Run Payroll',             'slug' => 'payroll.run',              'module' => 'payroll'],
            ['name' => 'Lock Payroll',            'slug' => 'payroll.lock',             'module' => 'payroll'],
            ['name' => 'Release Payment',         'slug' => 'payroll.release',          'module' => 'payroll'],
            ['name' => 'Reverse Payroll',         'slug' => 'payroll.reverse',          'module' => 'payroll'],
            // Exceptions
            ['name' => 'Resolve Exceptions',      'slug' => 'exceptions.resolve',       'module' => 'exceptions'],
            // Rejections
            ['name' => 'Enter Rejections',        'slug' => 'rejection.enter',          'module' => 'rejections'],
            ['name' => 'Dispute Rejections',      'slug' => 'rejection.dispute',        'module' => 'rejections'],
            // Advances & Loans
            ['name' => 'Approve Advances',        'slug' => 'advances.approve',         'module' => 'advances'],
            ['name' => 'Create Loans',            'slug' => 'loans.create',             'module' => 'loans'],
            // Rate Cards
            ['name' => 'Manage Rate Cards',       'slug' => 'rate_cards.manage',        'module' => 'rate_cards'],
            // Contractors
            ['name' => 'View Contractor Ledger',  'slug' => 'contractors.view_ledger',  'module' => 'contractors'],
            // Ghost Worker
            ['name' => 'Override Ghost Worker',   'slug' => 'ghost_worker.override',    'module' => 'ghost_worker'],
            // Compliance
            ['name' => 'View Compliance',         'slug' => 'compliance.view',          'module' => 'compliance'],
            // Reports
            ['name' => 'View All Reports',        'slug' => 'reports.view_all',         'module' => 'reports'],
            ['name' => 'View Own Reports',        'slug' => 'reports.view_own',         'module' => 'reports'],
        ];

        foreach ($permissions as &$p) {
            $p['created_at'] = now();
            $p['updated_at'] = now();
        }
        unset($p);

        DB::table('permissions')->insertOrIgnore($permissions);

        // Build slug → id map
        $permMap = DB::table('permissions')->pluck('id', 'slug');

        // --- Roles ---
        $roles = [
            ['name' => 'Admin',             'slug' => 'admin',             'description' => 'Full system access'],
            ['name' => 'HR Manager',        'slug' => 'hr_manager',        'description' => 'Manages workers, compliance, and leave'],
            ['name' => 'Payroll Manager',   'slug' => 'payroll_manager',   'description' => 'Runs and locks payroll'],
            ['name' => 'Supervisor',        'slug' => 'supervisor',        'description' => 'Enters and reviews production on the floor'],
            ['name' => 'Contractor',        'slug' => 'contractor',        'description' => 'Views own ledger and production'],
            ['name' => 'QC Inspector',      'slug' => 'qc_inspector',      'description' => 'Enters quality rejections'],
            ['name' => 'QC Supervisor',     'slug' => 'qc_supervisor',     'description' => 'Enters and disputes rejections'],
        ];

        foreach ($roles as &$r) {
            $r['created_at'] = now();
            $r['updated_at'] = now();
        }
        unset($r);

        DB::table('roles')->insertOrIgnore($roles);

        $roleMap = DB::table('roles')->pluck('id', 'slug');

        // --- Role → Permission assignments ---
        $assignments = [
            'admin' => $permMap->keys()->all(), // all permissions

            'hr_manager' => [
                'workers.create', 'workers.edit', 'workers.view_all',
                'advances.approve', 'loans.create',
                'compliance.view',
                'reports.view_all',
            ],

            'payroll_manager' => [
                'workers.view_all',
                'production.backfill',
                'payroll.run', 'payroll.lock', 'payroll.release', 'payroll.reverse',
                'exceptions.resolve',
                'advances.approve',
                'rate_cards.manage',
                'contractors.view_ledger',
                'reports.view_all',
            ],

            'supervisor' => [
                'workers.view_all',
                'production.enter', 'production.edit_same_day',
                'rejection.enter',
                'reports.view_own',
            ],

            'contractor' => [
                'contractors.view_ledger',
                'reports.view_own',
            ],

            'qc_inspector' => [
                'rejection.enter',
                'reports.view_own',
            ],

            'qc_supervisor' => [
                'rejection.enter', 'rejection.dispute',
                'reports.view_own',
            ],
        ];

        $pivotRows = [];
        foreach ($assignments as $roleSlug => $permSlugs) {
            $roleId = $roleMap[$roleSlug] ?? null;
            if (!$roleId) {
                continue;
            }
            foreach ($permSlugs as $permSlug) {
                $permId = $permMap[$permSlug] ?? null;
                if ($permId) {
                    $pivotRows[] = ['role_id' => $roleId, 'permission_id' => $permId];
                }
            }
        }

        // Deduplicate before insert
        $unique = collect($pivotRows)->unique(fn($r) => $r['role_id'] . '-' . $r['permission_id'])->values()->all();

        DB::table('role_permissions')->insertOrIgnore($unique);

        $this->command->info('Roles and permissions seeded.');
    }
}
