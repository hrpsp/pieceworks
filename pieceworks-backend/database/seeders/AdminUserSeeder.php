<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'admin@pieceworks.pk'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('password'),
            ]
        );

        $adminRole = DB::table('roles')->where('slug', 'admin')->first();

        if ($adminRole) {
            DB::table('user_roles')->insertOrIgnore([
                'user_id' => $user->id,
                'role_id' => $adminRole->id,
            ]);
        }

        $this->command->info("Admin user seeded: admin@pieceworks.pk / password");
    }
}
