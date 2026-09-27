<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            return;
        }

        // Cash collected from customers and cash held by a manager are protected
        // custody balances, not ordinary operating-disbursement sources.
        DB::table('chart_of_accounts')
            ->whereIn('system_key', ['CASH_ON_HAND', 'CASH_WITH_MANAGER'])
            ->update([
                'is_funding_source' => false,
                'updated_at' => now(),
            ]);

        if (! Schema::hasTable('accounting_payment_method_mappings')) {
            return;
        }

        // Existing Petty Cash / Cash with Manager mappings may pre-date the split
        // between customer-facing tenders and internal operating fund sources.
        // Normalize them so they no longer appear in Booking / Checkout / POS.
        $internalAccountIds = DB::table('chart_of_accounts')
            ->whereIn('system_key', ['PETTY_CASH', 'CASH_WITH_MANAGER'])
            ->pluck('id');

        if ($internalAccountIds->isNotEmpty()) {
            DB::table('accounting_payment_method_mappings')
                ->whereIn('cash_account_id', $internalAccountIds)
                ->update([
                    'customer_facing' => false,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. Restoring unsafe customer-facing/internal
        // classifications during rollback would be more harmful than preserving them.
    }
};
