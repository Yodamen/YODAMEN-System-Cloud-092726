<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_receipts')) {
            Schema::table('inventory_receipts', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_receipts', 'payment_status')) {
                    $table->string('payment_status', 20)->default('UNPAID')->after('delivery_fee')->index();
                }
                if (! Schema::hasColumn('inventory_receipts', 'payment_method_reference_id')) {
                    $table->foreignId('payment_method_reference_id')->nullable()->after('payment_status')
                        ->constrained('reference_values')->cascadeOnUpdate()->nullOnDelete();
                }
                if (! Schema::hasColumn('inventory_receipts', 'payment_method_snapshot')) {
                    $table->string('payment_method_snapshot', 150)->nullable()->after('payment_method_reference_id');
                }
                if (! Schema::hasColumn('inventory_receipts', 'payment_reference')) {
                    $table->string('payment_reference', 180)->nullable()->after('payment_method_snapshot');
                }
            });
        }

        if (Schema::hasTable('chart_of_accounts') && Schema::hasTable('businesses')) {
            foreach (DB::table('businesses')->pluck('id') as $businessId) {
                $exists = DB::table('chart_of_accounts')
                    ->where('business_id', $businessId)
                    ->where(function ($query) {
                        $query->where('system_key', 'INVENTORY_FREIGHT_IN')
                            ->orWhere('code', '5003');
                    })
                    ->first();

                if (! $exists) {
                    DB::table('chart_of_accounts')->insert([
                        'business_id' => $businessId,
                        'code' => '5003',
                        'name' => 'Inventory Freight-In / Delivery',
                        'account_type' => 'COST_OF_SALES',
                        'account_group' => 'Direct Cost',
                        'normal_balance' => 'DEBIT',
                        'posting_type' => 'POSTING',
                        'cash_flow_classification' => 'OPERATING',
                        'is_opex' => false,
                        'is_cash_account' => false,
                        'system_key' => 'INVENTORY_FREIGHT_IN',
                        'active' => true,
                        'notes' => 'Delivery/freight cost recorded separately from product weighted-average cost on Stock Received.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } elseif (! $exists->system_key && $exists->code === '5003') {
                    DB::table('chart_of_accounts')->where('id', $exists->id)->update([
                        'system_key' => 'INVENTORY_FREIGHT_IN',
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_receipts')) {
            Schema::table('inventory_receipts', function (Blueprint $table) {
                if (Schema::hasColumn('inventory_receipts', 'payment_method_reference_id')) {
                    $table->dropConstrainedForeignId('payment_method_reference_id');
                }
                $columns = collect(['payment_reference', 'payment_method_snapshot', 'payment_status'])
                    ->filter(fn (string $column) => Schema::hasColumn('inventory_receipts', $column))
                    ->all();
                if ($columns) $table->dropColumn($columns);
            });
        }

        // Keep the COA record on rollback if it may already be referenced by posted journals.
    }
};
