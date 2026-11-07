<?php

namespace App\Console\Commands;

use App\Services\Foundation\BrowserSetupService;
use Illuminate\Console\Command;
use Throwable;

class IssueSetupToken extends Command
{
    protected $signature = 'yodamen:setup-token {--from-stdin : Read a random setup code privately from stdin} {--check : Check whether browser setup is currently available}';
    protected $description = 'Enable first-time browser setup for an unused migrated installation.';

    public function handle(BrowserSetupService $setup): int
    {
        if ($this->option('check')) return $setup->status()['available'] ? self::SUCCESS : self::FAILURE;
        $token = $this->option('from-stdin') ? trim(stream_get_contents(STDIN)) : bin2hex(random_bytes(32));
        try { $setup->issue($token); }
        catch (Throwable $exception) {
            $this->error($exception instanceof \DomainException ? $exception->getMessage() : 'Could not enable setup. Run migrations and check that the database is unused.');
            return self::FAILURE;
        }
        $this->info('Browser setup enabled for 24 hours. Open /setup.');
        if (! $this->option('from-stdin')) $this->line('Setup code: '.$token);
        return self::SUCCESS;
    }
}
