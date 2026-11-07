<?php

namespace App\Services\Foundation;

use App\Models\Business;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BrowserSetupService
{
    public function status(): array
    {
        $status = ['available' => false, 'requires_setup' => false, 'token_expired' => false,
            'minimum_access_code_length' => (int) config('yodamen.authentication.minimum_access_code_length', 6)];
        // Upgrading an existing installation does not enable public registration.
        if (! Schema::hasTable('installation_setups') || Business::query()->exists() || User::query()->exists()) return $status;
        $setup = DB::table('installation_setups')->find(1);
        if (! $setup || ! $setup->token_hash || $setup->completed_at) return $status;
        $expired = ! $setup->expires_at || CarbonImmutable::parse($setup->expires_at)->isPast();
        return [...$status, 'requires_setup' => true, 'available' => ! $expired, 'token_expired' => $expired];
    }

    public function issue(string $token): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $token)) throw new DomainException('Use a cryptographically generated 64-character hex setup code.');
        DB::transaction(function () use ($token) {
            $this->lockInstallation();
            if (Business::query()->exists() || User::query()->exists()) throw new DomainException('Setup is closed for an initialized installation.');
            DB::table('installation_setups')->updateOrInsert(['id' => 1], [
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay(), 'completed_at' => null,
            ]);
        });
    }

    public function complete(string $token, array $input): Business
    {
        return DB::transaction(function () use ($token, $input) {
            $this->lockInstallation();
            if (Business::query()->exists() || User::query()->exists()) throw new DomainException('SETUP_CLOSED');
            $setup = DB::table('installation_setups')->where('id', 1)->lockForUpdate()->first();
            if (! $setup || ! $setup->token_hash || $setup->completed_at) throw new DomainException('SETUP_CLOSED');
            if (! $setup->expires_at || CarbonImmutable::parse($setup->expires_at)->isPast()) throw new DomainException('SETUP_TOKEN_EXPIRED');
            if (strlen($token) !== 64 || ! hash_equals($setup->token_hash, hash('sha256', $token))) throw new DomainException('SETUP_TOKEN_INVALID');
            $business = app(CleanInstallationService::class)->initialize($input);
            // Creating the owner and consuming the code commit or roll back together.
            DB::table('installation_setups')->where('id', 1)->update(['token_hash' => null, 'completed_at' => now()]);
            return $business;
        });
    }

    private function lockInstallation(): void
    {
        if (! DB::table('migrations')->orderBy('id')->lockForUpdate()->first()) throw new DomainException('Run migrations before enabling setup.');
    }
}
