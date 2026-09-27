<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts') || ! Schema::hasColumn('chart_of_accounts', 'is_funding_source')) {
            return;
        }

        // These are custody, clearing, or financing accounts. They may participate in
        // accounting workflows, but staff must not select them as ordinary operating
        // disbursement sources.
        DB::table('chart_of_accounts')
            ->whereIn('system_key', [
                'CASH_ON_HAND',
                'CASH_WITH_MANAGER',
                'POS_CLEARING',
                'OWNERS_CAPITAL',
                'LOANS_PAYABLE_OWNER',
            ])
            ->update([
                'is_funding_source' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Intentionally non-destructive. Re-enabling these protected accounts as
        // operating fund sources would restore an unsafe configuration.
    }
};
