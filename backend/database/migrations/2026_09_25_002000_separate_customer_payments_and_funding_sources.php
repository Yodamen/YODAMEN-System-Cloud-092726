<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chart_of_accounts') && ! Schema::hasColumn('chart_of_accounts', 'is_funding_source')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->boolean('is_funding_source')->default(false)->after('is_cash_account')->index();
            });
        }

        if (Schema::hasTable('accounting_payment_method_mappings') && ! Schema::hasColumn('accounting_payment_method_mappings', 'customer_facing')) {
            Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
                $table->boolean('customer_facing')->default(true)->after('cash_account_id')->index();
            });
        }

        if (Schema::hasTable('chart_of_accounts') && Schema::hasColumn('chart_of_accounts', 'is_funding_source')) {
            // Existing cash/bank/wallet/petty-cash accounts are valid company funding sources.
            DB::table('chart_of_accounts')
                ->where('is_cash_account', true)
                ->update(['is_funding_source' => true]);

            // Owner-paid company costs are a legitimate non-cash funding source.
            DB::table('chart_of_accounts')
                ->where('system_key', 'OWNERS_CAPITAL')
                ->update(['is_funding_source' => true]);
        }

        if (
            Schema::hasTable('accounting_payment_method_mappings')
            && Schema::hasColumn('accounting_payment_method_mappings', 'customer_facing')
            && Schema::hasTable('reference_values')
        ) {
            // Old reference lists sometimes included internal funding labels in Payment Method.
            // Keep the mapping for history but hide obvious internal-only sources from customers.
            $internalOnlyIds = DB::table('reference_values')
                ->where(function ($query) {
                    $query->whereRaw("UPPER(code) LIKE '%OWNER%'")
                        ->orWhereRaw("UPPER(display_value) LIKE '%OWNER%'")
                        ->orWhereRaw("UPPER(code) LIKE '%CAPITAL%'")
                        ->orWhereRaw("UPPER(display_value) LIKE '%CAPITAL%'")
                        ->orWhereRaw("UPPER(code) LIKE '%PETTY%'")
                        ->orWhereRaw("UPPER(display_value) LIKE '%PETTY%'");
                })
                ->pluck('id');

            if ($internalOnlyIds->isNotEmpty()) {
                DB::table('accounting_payment_method_mappings')
                    ->whereIn('payment_method_reference_id', $internalOnlyIds)
                    ->update(['customer_facing' => false]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('accounting_payment_method_mappings') && Schema::hasColumn('accounting_payment_method_mappings', 'customer_facing')) {
            Schema::table('accounting_payment_method_mappings', function (Blueprint $table) {
                $table->dropColumn('customer_facing');
            });
        }

        if (Schema::hasTable('chart_of_accounts') && Schema::hasColumn('chart_of_accounts', 'is_funding_source')) {
            Schema::table('chart_of_accounts', function (Blueprint $table) {
                $table->dropColumn('is_funding_source');
            });
        }
    }
};
