<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Development\DevelopmentResetService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DevelopmentTestReset extends Command
{
    private const ARM_FILE = 'private/yodamen-devreset-test-arm.json';

    private const ARM_MINUTES = 5;

    protected $signature = 'yodamen:devreset-test
                            {action=preview : preview, arm, execute, or cancel}
                            {--username= : Active Super Admin username}';

    protected $description = 'Safely reset only customers marked as Test Customer and their linked transactions.';

    public function handle(DevelopmentResetService $reset): int
    {
        if (! $this->isAllowedEnvironment()) {
            $this->error('TEST DEVRESET is blocked outside the local/development environment.');
            $this->line('Current APP_ENV: '.app()->environment());

            return self::FAILURE;
        }

        $action = strtolower(trim((string) $this->argument('action')));

        return match ($action) {
            'preview' => $this->preview($reset),
            'arm' => $this->arm($reset),
            'execute' => $this->runReset($reset),
            'cancel' => $this->cancel(),
            default => $this->invalidAction($action),
        };
    }

    private function preview(DevelopmentResetService $reset): int
    {
        try {
            $customers = $reset->testCustomers();
            $counts = $reset->previewTest();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('YODAMEN TEST-ONLY DEVRESET PREVIEW');
        $this->line('Environment: '.app()->environment());
        $this->line('Database: '.(string) config('database.connections.'.config('database.default').'.database'));
        $this->newLine();

        if ($customers === []) {
            $this->comment('No customers are currently marked as Test Customer.');
        } else {
            $this->comment('Test Customers targeted:');
            $this->table(
                ['Customer ID', 'Customer Code', 'Customer Name'],
                array_map(fn (array $customer) => [$customer['id'], $customer['code'], $customer['name']], $customers),
            );
        }

        $rows = [];
        $total = 0;

        foreach ($counts as $table => $count) {
            $rows[] = [$table, number_format($count)];
            $total += $count;
        }

        $this->table(['Test-data table', 'Rows to delete'], $rows);
        $this->line('Total rows currently targeted: '.number_format($total));
        $this->newLine();
        $this->warn('Only Test Customers and their linked transaction records are targeted.');
        $this->line('Real customers, their transactions, master data, audit logs, migrations, and code sequences are preserved.');
        $this->newLine();
        $this->warn('Nothing was deleted.');
        $this->line('Next step: php artisan yodamen:devreset-test arm');

        return self::SUCCESS;
    }

    private function arm(DevelopmentResetService $reset): int
    {
        try {
            $customers = $reset->testCustomers();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($customers === []) {
            $this->warn('There are no Test Customers to reset. Nothing was armed.');

            return self::SUCCESS;
        }

        $user = $this->resolveSuperAdmin();

        if (! $user) {
            return self::FAILURE;
        }

        if (! $this->verifyAccessCode($user)) {
            return self::FAILURE;
        }

        $now = Carbon::now();
        $expiresAt = $now->copy()->addMinutes(self::ARM_MINUTES);

        Storage::disk(config('filesystems.state_disk'))->put(self::ARM_FILE, json_encode([
            'nonce' => (string) Str::uuid(),
            'user_id' => $user->id,
            'username' => $user->username,
            'armed_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'environment' => app()->environment(),
            'database' => (string) config('database.connections.'.config('database.default').'.database'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->warn('YODAMEN TEST-ONLY DEVRESET IS ARMED.');
        $this->line('Armed by: '.$user->username);
        $this->line('Expires: '.$expiresAt->format('Y-m-d H:i:s').' ('.config('app.timezone').')');
        $this->line('Execute within '.self::ARM_MINUTES.' minutes: php artisan yodamen:devreset-test execute');
        $this->line('Cancel instead: php artisan yodamen:devreset-test cancel');

        return self::SUCCESS;
    }

    private function runReset(DevelopmentResetService $reset): int
    {
        $arm = $this->readArmState();

        if (! $arm) {
            $this->error('TEST DEVRESET is not armed. Run preview, then arm, before execute.');

            return self::FAILURE;
        }

        $expiresAt = Carbon::parse((string) ($arm['expires_at'] ?? '1970-01-01'));

        if (Carbon::now()->greaterThan($expiresAt)) {
            $this->removeArmState();
            $this->error('The TEST DEVRESET arm window expired. Run arm again.');

            return self::FAILURE;
        }

        if (($arm['environment'] ?? null) !== app()->environment()) {
            $this->removeArmState();
            $this->error('The environment changed after TEST DEVRESET was armed. The arm state was cancelled.');

            return self::FAILURE;
        }

        $currentDatabase = (string) config('database.connections.'.config('database.default').'.database');

        if (($arm['database'] ?? null) !== $currentDatabase) {
            $this->removeArmState();
            $this->error('The database changed after TEST DEVRESET was armed. The arm state was cancelled.');

            return self::FAILURE;
        }

        $user = User::query()->with('role')->find($arm['user_id'] ?? null);

        if (! $user || ! $user->isActive() || ! $user->isSuperAdmin()) {
            $this->removeArmState();
            $this->error('The Super Admin who armed TEST DEVRESET is no longer eligible.');

            return self::FAILURE;
        }

        if (! $this->verifyAccessCode($user)) {
            return self::FAILURE;
        }

        try {
            $customers = $reset->testCustomers();
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($customers === []) {
            $this->removeArmState();
            $this->warn('No Test Customers remain. Nothing was deleted and the arm state was cleared.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('This will permanently delete ONLY customers marked as Test Customer and their linked transaction data.');
        $this->line('Real customers and their transactions will remain untouched.');

        if (! $this->confirm('Execute TEST-ONLY DEVRESET now?', false)) {
            $this->warn('TEST DEVRESET was not executed. The current arm state remains active until it expires.');

            return self::SUCCESS;
        }

        try {
            $deleted = $reset->executeTest();
        } catch (\Throwable $exception) {
            $this->error('TEST DEVRESET failed. Database transaction was rolled back.');
            $this->line($exception->getMessage());

            return self::FAILURE;
        }

        $this->removeArmState();

        $rows = [];
        $total = 0;

        foreach ($deleted as $table => $count) {
            $rows[] = [$table, number_format($count)];
            $total += $count;
        }

        $this->newLine();
        $this->info('YODAMEN TEST-ONLY DEVRESET COMPLETED.');
        $this->table(['Cleared test-data table', 'Deleted rows'], $rows);
        $this->line('Total deleted rows: '.number_format($total));
        $this->line('Real customers, master data, code sequences, audit logs, and migration history were preserved.');

        return self::SUCCESS;
    }

    private function cancel(): int
    {
        if (! Storage::disk(config('filesystems.state_disk'))->exists(self::ARM_FILE)) {
            $this->line('TEST DEVRESET is not currently armed.');

            return self::SUCCESS;
        }

        $this->removeArmState();
        $this->info('TEST DEVRESET arm state cancelled.');

        return self::SUCCESS;
    }

    private function resolveSuperAdmin(): ?User
    {
        $username = strtolower(trim((string) ($this->option('username') ?: $this->ask('Super Admin username'))));

        if ($username === '') {
            $this->error('Super Admin username is required.');

            return null;
        }

        $user = User::query()
            ->with('role')
            ->whereRaw('LOWER(username) = ?', [$username])
            ->first();

        if (! $user || ! $user->isActive() || ! $user->isSuperAdmin()) {
            $this->error('An active Super Admin account with that username was not found.');

            return null;
        }

        return $user;
    }

    private function verifyAccessCode(User $user): bool
    {
        $accessCode = (string) ($this->secret('Super Admin Access Code') ?? '');

        if ($accessCode === '' || ! Hash::check($accessCode, $user->password)) {
            $this->error('Invalid Super Admin Access Code.');

            return false;
        }

        return true;
    }

    /** @return array<string, mixed>|null */
    private function readArmState(): ?array
    {
        if (! Storage::disk(config('filesystems.state_disk'))->exists(self::ARM_FILE)) {
            return null;
        }

        $decoded = json_decode((string) Storage::disk(config('filesystems.state_disk'))->get(self::ARM_FILE), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function removeArmState(): void
    {
        Storage::disk(config('filesystems.state_disk'))->delete(self::ARM_FILE);
    }

    private function isAllowedEnvironment(): bool
    {
        return in_array(app()->environment(), ['local', 'development'], true);
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown TEST DEVRESET action: {$action}");
        $this->line('Allowed actions: preview, arm, execute, cancel');

        return self::FAILURE;
    }
}
