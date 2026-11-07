<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\User;
use App\Services\Foundation\CleanInstallationService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Throwable;

class InitializeInstallation extends Command
{
    protected $signature = 'yodamen:initialize {--input-json : Read private setup JSON from stdin} {--check : Check whether first setup already completed}';

    protected $description = 'Create a new business and first Super Admin in an unused migrated database.';

    public function handle(CleanInstallationService $setup): int
    {
        if ($this->option('check')) {
            if (Business::query()->count() !== 1) return self::FAILURE;
            $business = Business::query()->value('id');
            $ready = User::query()->where('business_id', $business)->where('status', 'ACTIVE')
                ->whereHas('role', fn ($q) => $q->where('is_super_admin', true)->where('active', true))->exists();
            if ($ready) { $this->info('First setup already completed.'); return self::SUCCESS; }
            return self::FAILURE;
        }
        if (Business::query()->exists() || User::query()->exists()) {
            $this->error('This database already has a business or user. Nothing was reset.');
            return self::FAILURE;
        }
        try {
            if ($this->option('input-json')) {
                $data = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($data) || array_is_list($data)) throw new \DomainException('Expected one setup JSON object on stdin.');
            } else {
                if (! $this->input->isInteractive()) {
                    $this->error('Use an interactive terminal or --input-json with a private setup file on stdin.');
                    return self::FAILURE;
                }
                $data = [
                    'business_name' => trim((string) $this->ask('Business name')),
                    'business_code' => trim((string) $this->ask('Business code', 'YDM')),
                    'branch_name' => trim((string) $this->ask('Main branch name', 'Main Branch')),
                    'username' => trim((string) $this->ask('Super Admin username')),
                    'display_name' => trim((string) $this->ask('Super Admin display name')),
                    'access_code' => (string) $this->secret('Choose Super Admin access code'),
                ];
                $confirmation = (string) $this->secret('Confirm access code');
                if (! hash_equals($data['access_code'], $confirmation)) {
                    $this->error('Access code confirmation does not match.');
                    return self::FAILURE;
                }
            }
            $business = $setup->initialize($data);
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->first());
            return self::FAILURE;
        } catch (Throwable $exception) {
            // Do not render SQL, bindings or input JSON containing the access code.
            $this->error($exception instanceof \DomainException ? $exception->getMessage() : 'Initialization failed. No setup changes were committed.');
            return self::FAILURE;
        }
        $this->info('New installation initialized. Sign in with the Super Admin credentials you chose.');
        $this->line('Business code: '.$business->code);
        $this->line('Installation ID: '.$business->license_installation_id);
        $this->line('Premium trial: 50 activities. No client transactions were created.');
        return self::SUCCESS;
    }
}
