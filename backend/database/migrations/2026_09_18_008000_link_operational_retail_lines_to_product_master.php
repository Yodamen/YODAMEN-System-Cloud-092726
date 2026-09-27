<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['booking_retail_lines', 'queue_retail_lines', 'visit_retail_lines'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, 'retail_product_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->foreignId('retail_product_id')
                        ->nullable()
                        ->after('legacy_retail_item_code')
                        ->constrained('retail_products')
                        ->nullOnDelete();
                });
            }

            if (! Schema::hasColumn($tableName, 'sku_snapshot')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->string('sku_snapshot', 100)
                        ->nullable()
                        ->after('retail_product_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['booking_retail_lines', 'queue_retail_lines', 'visit_retail_lines'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (Schema::hasColumn($tableName, 'sku_snapshot')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropColumn('sku_snapshot');
                });
            }

            if (Schema::hasColumn($tableName, 'retail_product_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('retail_product_id');
                });
            }
        }
    }
};
