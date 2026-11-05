<?php
// Runs inside the new application image before reopening business access.
try {
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$before = json_decode(file_get_contents('php://stdin'), true, 32, JSON_THROW_ON_ERROR);
if (! hash_equals($before['key_hash'], hash('sha256', (string) config('app.key')))) {
    fwrite(STDERR, "Application key changed.\n"); exit(1);
}
foreach ($before['counts'] as $table => $count) {
    if (Illuminate\Support\Facades\DB::table($table)->count() !== $count) {
        fwrite(STDERR, "Existing record counts changed during upgrade.\n"); exit(1);
    }
}
foreach ($before['businesses'] as $id => $values) {
    $business = Illuminate\Support\Facades\DB::table('businesses')->find($id);
    if (! $business) { fwrite(STDERR, "Existing business missing.\n"); exit(1); }
    foreach ($values as $field => $value) {
        $actual = $field === 'token_hash' ? hash('sha256', (string) $business->license_token) : $business->$field;
        if ($actual !== $value) { fwrite(STDERR, "Existing license identity, revision or usage changed.\n"); exit(1); }
    }
}
$ran = Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all();
$pending = array_diff(array_map(fn ($f) => basename($f, '.php'), glob(__DIR__.'/database/migrations/*.php')), $ran);
if ($pending) { fwrite(STDERR, "Pending migrations remain.\n"); exit(1); }
foreach (App\Models\Business::query()->cursor() as $business) {
    if (! $business->license_installation_id) { fwrite(STDERR, "Missing installation ID.\n"); exit(1); }
    $subscription = app(App\Services\Subscriptions\SubscriptionService::class)->snapshot($business);
    if ($subscription['status'] === 'invalid') { fwrite(STDERR, "An existing license is invalid under this release. Investigate before reopening.\n"); exit(1); }
    if ($business->license_token && ($subscription['status'] !== 'active' || $subscription['expires_at'] !== null)) {
        fwrite(STDERR, "Paid plan did not remain permanent.\n"); exit(1);
    }
}
echo "Migrations and subscription state verified.\n";

} catch (Throwable $exception) {
    fwrite(STDERR, "Patch state verification could not complete. Inspect the PHP/container logs.\n");
    exit(1);
}
