<?php
try {
    require __DIR__.'/vendor/autoload.php';
    $app = require __DIR__.'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! extension_loaded('sodium')) throw new RuntimeException('PHP Sodium is missing.');
    if (! Illuminate\Support\Facades\Schema::hasTable('migrations')) throw new RuntimeException('Dump has no migration history. Supply the complete database dump.');
    $pendingSetup = in_array('--allow-pending-setup', $argv, true)
        && app(App\Services\Foundation\BrowserSetupService::class)->status()['available'];
    if (! $pendingSetup && ! App\Models\User::whereHas('role', fn ($q) => $q->where('is_super_admin', true))->exists()) {
        throw new RuntimeException('No Super Admin was imported. Verify that the complete dump was supplied.');
    }
    if (in_array('--before-migrations', $argv, true)) { echo "Imported schema and Super Admin verified.\n"; exit(0); }
    $ran = Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
    $pending = array_diff(array_map(fn ($f) => basename($f, '.php'), glob(__DIR__.'/database/migrations/*.php')), $ran);
    if ($pending) throw new RuntimeException('Pending migrations remain. Existing installations must use a reviewed patch.');
    foreach (App\Models\Business::query()->cursor() as $business) {
        if (! $business->license_installation_id) throw new RuntimeException('Missing license installation identity.');
        $state = app(App\Services\Subscriptions\SubscriptionService::class)->snapshot($business);
        if ($state['status'] === 'invalid') throw new RuntimeException('An imported license is invalid. Investigate it before opening the app.');
        if ($business->license_token && ($state['status'] !== 'active' || $state['expires_at'] !== null)) {
            throw new RuntimeException('Imported paid license was not preserved as permanent.');
        }
    }
    echo "Database migrations and subscription state verified.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, "Installation verification failed: ".$exception->getMessage()."\n");
    exit(1);
}
