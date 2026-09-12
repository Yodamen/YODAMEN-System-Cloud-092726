<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class InitialSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $username = strtolower(trim((string) env('YODAMEN_SUPER_ADMIN_USERNAME')));
        $accessCode = trim((string) env('YODAMEN_SUPER_ADMIN_ACCESS_CODE'));

        if ($username === '') {
            $this->command?->warn('Initial Super Admin was not seeded because YODAMEN_SUPER_ADMIN_USERNAME is not set.');
            return;
        }

        $business = Business::query()->where('code', 'YDM')->firstOrFail();
        $branch = Branch::query()->where('code', 'YDM-SJC')->firstOrFail();
        $role = Role::query()->where('code', 'ROLE-SA')->firstOrFail();

        $existing = User::query()->where('username', $username)->first();

        if ($existing) {
            $existing->forceFill([
                'business_id' => $business->id,
                'default_branch_id' => $existing->default_branch_id ?: $branch->id,
                'role_id' => $role->id,
                'name' => env('YODAMEN_SUPER_ADMIN_DISPLAY_NAME', $existing->name ?: 'Super Admin'),
                'display_name' => env('YODAMEN_SUPER_ADMIN_DISPLAY_NAME', $existing->display_name ?: 'Super Admin'),
                'status' => 'ACTIVE',
            ])->save();

            $this->command?->info('Existing Super Admin preserved. Access Code and force-reset state were not changed.');
            return;
        }

        if ($accessCode === '') {
            $this->command?->warn('Initial Super Admin was not created because YODAMEN_SUPER_ADMIN_ACCESS_CODE is not set.');
            return;
        }

        if (mb_strlen($accessCode) < (int) config('yodamen.authentication.minimum_access_code_length', 6)) {
            $this->command?->error('Initial Super Admin Access Code is shorter than the configured minimum.');
            return;
        }

        User::query()->create([
            'username' => $username,
            'code' => 'USR-0001',
            'business_id' => $business->id,
            'default_branch_id' => $branch->id,
            'role_id' => $role->id,
            'name' => env('YODAMEN_SUPER_ADMIN_DISPLAY_NAME', 'Super Admin'),
            'display_name' => env('YODAMEN_SUPER_ADMIN_DISPLAY_NAME', 'Super Admin'),
            'email' => env('YODAMEN_SUPER_ADMIN_EMAIL') ?: null,
            'password' => $accessCode,
            'status' => 'ACTIVE',
            'force_reset' => true,
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);
    }
}
