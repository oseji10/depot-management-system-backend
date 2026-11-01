<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('SUPERADMIN_EMAIL');
        $password = env('SUPERADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->command?->warn('SUPERADMIN_EMAIL / SUPERADMIN_PASSWORD not set; skipping super admin.');

            return;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->fill(['name' => env('SUPERADMIN_NAME', 'Platform Admin'), 'password' => $password]);
        $user->forceFill(['is_super_admin' => true, 'store_id' => null, 'branch_id' => null, 'is_active' => true])->save();
    }
}
