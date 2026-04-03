<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class AdminController extends Controller
{
    public function listUsers(): JsonResponse
    {
        // Use raw DB to avoid Eloquent model relationship issues
        $users = DB::table('users')
            ->select('users.id', 'users.name', 'users.email', 'users.created_at')
            ->orderBy('users.name')
            ->get();

        $result = $users->map(function ($u) {
            // Try user_roles pivot, fall back to role_user, fall back to direct role column
            $roleNames = [];
            try {
                $roleNames = DB::table('user_roles')
                    ->join('roles', 'user_roles.role_id', '=', 'roles.id')
                    ->where('user_roles.user_id', $u->id)
                    ->pluck('roles.name')
                    ->toArray();
            } catch (\Exception $e) {
                try {
                    $roleNames = DB::table('role_user')
                        ->join('roles', 'role_user.role_id', '=', 'roles.id')
                        ->where('role_user.user_id', $u->id)
                        ->pluck('roles.name')
                        ->toArray();
                } catch (\Exception $e2) {
                    $roleNames = [];
                }
            }
            return [
                'id'         => $u->id,
                'name'       => $u->name,
                'email'      => $u->email,
                'role'       => $roleNames[0] ?? 'No role',
                'roles'      => $roleNames,
                'created_at' => $u->created_at ? date('Y-m-d', strtotime($u->created_at)) : null,
            ];
        });

        return response()->json(['status' => 'success', 'message' => 'Users retrieved', 'data' => $result]);
    }

    public function inviteUser(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|unique:users,email',
            'role_slug' => 'required|string',
            'password'  => 'required|string|min:8',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 'error', 'message' => $v->errors()->first(), 'data' => null], 422);
        }
        $userId = DB::table('users')->insertGetId([
            'name'       => $request->name,
            'email'      => $request->email,
            'password'   => Hash::make($request->password),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('slug', $request->role_slug)->first();
        if ($role) {
            // Try user_roles first, then role_user
            try {
                DB::table('user_roles')->insert(['user_id' => $userId, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
            } catch (\Exception $e) {
                try {
                    DB::table('role_user')->insert(['user_id' => $userId, 'role_id' => $role->id]);
                } catch (\Exception $e2) {}
            }
        }
        return response()->json(['status' => 'success', 'message' => 'User invited successfully', 'data' => ['id' => $userId, 'name' => $request->name, 'email' => $request->email]], 201);
    }

    public function listLocations(): JsonResponse
    {
        if (!DB::getSchemaBuilder()->hasTable('factory_locations')) {
            return response()->json(['status' => 'success', 'message' => 'Factory locations retrieved', 'data' => []]);
        }
        $locations = DB::table('factory_locations')->orderBy('name')->get()->map(fn ($l) => [
            'id'        => $l->id,
            'name'      => $l->name ?? '',
            'city'      => $l->city ?? '',
            'province'  => $l->province ?? '',
            'address'   => $l->address ?? null,
            'is_active' => isset($l->is_active) ? (bool)$l->is_active : true,
        ]);
        return response()->json(['status' => 'success', 'message' => 'Factory locations retrieved', 'data' => $locations]);
    }

    public function createLocation(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:255', 'city' => 'required|string|max:100',
            'province' => 'required|string|max:100', 'address' => 'nullable|string|max:500', 'is_active' => 'boolean',
        ]);
        if ($v->fails()) {
            return response()->json(['status' => 'error', 'message' => $v->errors()->first(), 'data' => null], 422);
        }
        if (!DB::getSchemaBuilder()->hasTable('factory_locations')) {
            DB::getSchemaBuilder()->create('factory_locations', function ($table) {
                $table->id(); $table->string('name'); $table->string('city');
                $table->string('province'); $table->string('address')->nullable();
                $table->boolean('is_active')->default(true); $table->timestamps();
            });
        }
        $id = DB::table('factory_locations')->insertGetId([
            'name' => $request->name, 'city' => $request->city, 'province' => $request->province,
            'address' => $request->address, 'is_active' => $request->boolean('is_active', true),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return response()->json(['status' => 'success', 'message' => 'Factory location added', 'data' => ['id' => $id]], 201);
    }

    private function defaultConfig(): array
    {
        return [
            'eobi_employer_rate_pct' => 5.00, 'pessi_employer_rate_pct' => 6.00,
            'min_wage_punjab' => 37000, 'min_wage_sindh' => 35000,
            'min_wage_kpk' => 36000, 'min_wage_balochistan' => 34000,
            'wht_threshold' => 100000, 'wht_rate_non_filer_pct' => 2.50,
        ];
    }

    public function getComplianceConfig(): JsonResponse
    {
        if (DB::getSchemaBuilder()->hasTable('compliance_configs')) {
            $row = DB::table('compliance_configs')->first();
            if ($row) return response()->json(['status' => 'success', 'message' => 'Compliance config retrieved', 'data' => (array)$row]);
        }
        return response()->json(['status' => 'success', 'message' => 'Compliance config retrieved (defaults)', 'data' => $this->defaultConfig()]);
    }

    public function patchComplianceConfig(Request $request): JsonResponse
    {
        $data = array_merge($this->defaultConfig(), $request->only(array_keys($this->defaultConfig())));
        if (!DB::getSchemaBuilder()->hasTable('compliance_configs')) {
            DB::getSchemaBuilder()->create('compliance_configs', function ($table) {
                $table->id();
                foreach (['eobi_employer_rate_pct','pessi_employer_rate_pct','wht_rate_non_filer_pct'] as $col)
                    $table->decimal($col, 5, 2)->default(0);
                foreach (['min_wage_punjab','min_wage_sindh','min_wage_kpk','min_wage_balochistan','wht_threshold'] as $col)
                    $table->decimal($col, 12, 2)->default(0);
                $table->timestamps();
            });
        }
        $data['updated_at'] = now();
        $existing = DB::table('compliance_configs')->first();
        if ($existing) DB::table('compliance_configs')->where('id', $existing->id)->update($data);
        else { $data['created_at'] = now(); DB::table('compliance_configs')->insert($data); }
        return response()->json(['status' => 'success', 'message' => 'Compliance config updated', 'data' => $data]);
    }
}