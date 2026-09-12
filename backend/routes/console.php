<?php

use App\Models\User;
use App\Services\Sales\SaleDraftService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('yodamen:sales-sync', function () {
    $actor = User::query()
        ->where('status', 'ACTIVE')
        ->whereHas('role', fn ($query) => $query->where('is_super_admin', true))
        ->orderBy('id')
        ->first();

    if (! $actor) {
        $this->error('No active Super Admin user is available for the Sales sync audit actor.');
        return 1;
    }

    $sales = app(SaleDraftService::class)->syncCompletedVisits($actor);
    $this->info('Sales sync complete. Created '.$sales->count().' Sale draft(s).');
    foreach ($sales as $sale) {
        $this->line($sale->code.' ← Visit '.$sale->visit?->code);
    }

    return 0;
})->purpose('Create missing Sale drafts for completed YODAMEN Visits.');
