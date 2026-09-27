<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('suppliers') && ! Schema::hasColumn('suppliers', 'code_name')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->string('code_name', 80)->nullable()->after('company_name');
                $table->index(['business_id', 'code_name'], 'suppliers_business_codename_idx');
            });

            DB::table('suppliers')->orderBy('id')->get(['id', 'company_name', 'code'])->each(function ($supplier) {
                $fallback = trim((string) ($supplier->company_name ?? ''));
                if ($fallback === '') $fallback = (string) ($supplier->code ?? 'Supplier');
                DB::table('suppliers')->where('id', $supplier->id)->update([
                    'code_name' => substr($fallback, 0, 80),
                ]);
            });
        }

        if (Schema::hasTable('inventory_receipts') && ! Schema::hasColumn('inventory_receipts', 'delivery_fee')) {
            Schema::table('inventory_receipts', function (Blueprint $table) {
                $table->decimal('delivery_fee', 14, 2)->default(0)->after('total_cost');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('inventory_receipts') && Schema::hasColumn('inventory_receipts', 'delivery_fee')) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->dropColumn('delivery_fee'));
        }

        if (Schema::hasTable('suppliers') && Schema::hasColumn('suppliers', 'code_name')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropIndex('suppliers_business_codename_idx');
                $table->dropColumn('code_name');
            });
        }
    }
};
