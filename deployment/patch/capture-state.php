<?php
// Compatible with the pre-licensing image. Never print private runtime values.
try {
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$state = ['key_hash' => hash('sha256', (string) config('app.key')), 'counts' => [], 'businesses' => []];
foreach (['businesses', 'users', 'customers', 'bookings', 'sales'] as $table) {
    $state['counts'][$table] = Illuminate\Support\Facades\DB::table($table)->count();
}
$fields = array_filter(['license_installation_id', 'license_revision', 'trial_uses'], fn ($field) => Illuminate\Support\Facades\Schema::hasColumn('businesses', $field));
$tokenExists = Illuminate\Support\Facades\Schema::hasColumn('businesses', 'license_token');
foreach (Illuminate\Support\Facades\DB::table('businesses')->get() as $business) {
    $values = [];
    foreach ($fields as $field) $values[$field] = $business->$field;
    if ($tokenExists) $values['token_hash'] = hash('sha256', (string) $business->license_token);
    $state['businesses'][(string) $business->id] = $values;
}
echo json_encode($state, JSON_THROW_ON_ERROR)."\n";

} catch (Throwable $exception) {
    fwrite(STDERR, "Patch state verification could not complete. Inspect the PHP/container logs.\n");
    exit(1);
}
