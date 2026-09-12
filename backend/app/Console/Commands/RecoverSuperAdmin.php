<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverSuperAdmin extends Command
{
    protected $signature = 'yodamen:super-admin-recover';

    protected $description = 'Recover the YODAMEN Super Admin profile and Access Code from the command line.';

    public function handle(): int
    {
        $role = Role::query()->where('code', 'ROLE-SA')->first();

        if (! $role) {
            $this->error('ROLE-SA was not found. Run the foundation seeders first.');
            return self::FAILURE;
        }

        $preferredUsername = strtolower(trim((string) env('YODAMEN_SUPER_ADMIN_USERNAME')));

        $user = User::query()
            ->where('role_id', $role->id)
            ->when($preferredUsername !== '', function ($query) use ($preferredUsername) {
                $query->orderByRaw('CASE WHEN LOWER(username) = ? THEN 0 ELSE 1 END', [$preferredUsername]);
            })
            ->orderBy('id')
            ->first();

        if (! $user) {
            $this->error('No Super Admin user was found. Run InitialSuperAdminSeeder first.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Current Super Admin');
        $this->line('Username: '.$user->username);
        $this->line('Display name: '.($user->display_name ?: $user->name));
        $this->line('Status: '.$user->status);
        $this->newLine();

        $displayName = trim((string) $this->ask('Display name', $user->display_name ?: $user->name ?: 'Super Admin'));
        $username = strtolower(trim((string) $this->ask('Username', $user->username)));

        if ($displayName === '') {
            $this->error('Display name cannot be blank.');
            return self::FAILURE;
        }

        if ($username === '') {
            $this->error('Username cannot be blank.');
            return self::FAILURE;
        }

        $duplicate = User::query()
            ->whereRaw('LOWER(username) = ?', [$username])
            ->whereKeyNot($user->id)
            ->exists();

        if ($duplicate) {
            $this->error('That username is already used by another account.');
            return self::FAILURE;
        }

        $accessCode = (string) ($this->secret('New Access Code (press Enter to keep the current code)') ?? '');

        if ($accessCode !== '') {
            $minimumLength = (int) config('yodamen.authentication.minimum_access_code_length', 6);

            if (mb_strlen($accessCode) < $minimumLength) {
                $this->error("Access Code must contain at least {$minimumLength} characters.");
                return self::FAILURE;
            }

            $confirmation = (string) ($this->secret('Confirm new Access Code') ?? '');

            if (! hash_equals($accessCode, $confirmation)) {
                $this->error('The Access Code confirmation does not match.');
                return self::FAILURE;
            }
        }

        DB::transaction(function () use ($user, $displayName, $username, $accessCode): void {
            $changes = [
                'username' => $username,
                'name' => $displayName,
                'display_name' => $displayName,
                'status' => 'ACTIVE',
                'failed_attempts' => 0,
                'locked_until' => null,
            ];

            if ($accessCode !== '') {
                $changes['password'] = $accessCode;
                $changes['force_reset'] = false;
            }

            $user->forceFill($changes)->save();
        });

        $this->newLine();
        $this->info('Super Admin recovery completed.');
        $this->line('Username: '.$user->fresh()->username);
        $this->line('Display name: '.$user->fresh()->display_name);
        $this->line('Role: Super Admin');
        $this->line('Account lock: cleared');

        return self::SUCCESS;
    }
}
