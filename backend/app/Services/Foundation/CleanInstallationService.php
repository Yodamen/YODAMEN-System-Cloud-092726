<?php

namespace App\Services\Foundation;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CleanInstallationSeeder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CleanInstallationService
{
    public function initialize(array $input): Business
    {
        if (is_string($input['username'] ?? null)) $input['username'] = strtolower(trim($input['username']));
        $input['business_code'] ??= 'YDM';
        if (is_string($input['business_code'])) $input['business_code'] = strtoupper(trim($input['business_code']));
        if (is_string($input['access_code'] ?? null)) $input['access_code'] = trim($input['access_code']);
        $data = Validator::make($input, [
            'business_name' => ['required', 'string', 'max:255'],
            'business_code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{0,19}$/'],
            'branch_name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_.-]{2,49}$/'],
            'display_name' => ['required', 'string', 'max:255'],
            'access_code' => ['required', 'string', 'min:'.config('yodamen.authentication.minimum_access_code_length', 6), 'max:72', function ($attribute, $value, $fail) {
                if (strlen($value) > 72) $fail('The access code must be at most 72 bytes.');
            }],
        ])->validate();

        return DB::transaction(function () use ($data) {
            // Every migrated database has this stable lock target, even with no
            // business rows. Concurrent initializers serialize before checking.
            if (! DB::table('migrations')->orderBy('id')->lockForUpdate()->first()) {
                throw new DomainException('Run database migrations before initialization.');
            }
            if (Business::query()->exists() || User::query()->exists()) {
                throw new DomainException('This database already has a business or user. Nothing was reset.');
            }

            // Existing configuration seeders use YDM during initial bootstrap.
            // Rename the business only after every seeder has completed.
            app(CleanInstallationSeeder::class)->run();
            $business = Business::query()->where('code', 'YDM')->firstOrFail();
            $business->update(['code' => $data['business_code'], 'name' => $data['business_name']]);
            $branch = Branch::query()->where('business_id', $business->id)->firstOrFail();
            $branch->update(['code' => $data['business_code'].'-MAIN', 'name' => $data['branch_name'], 'short_code' => 'MAIN']);
            $role = Role::query()->where('business_id', $business->id)->where('is_super_admin', true)->firstOrFail();
            User::query()->create([
                'business_id' => $business->id, 'default_branch_id' => $branch->id,
                'role_id' => $role->id, 'code' => 'USR-0001',
                'username' => $data['username'], 'name' => $data['display_name'],
                'display_name' => $data['display_name'], 'password' => $data['access_code'],
                'status' => 'ACTIVE', 'force_reset' => false, 'failed_attempts' => 0,
            ]);

            return $business->fresh();
        });
    }
}
