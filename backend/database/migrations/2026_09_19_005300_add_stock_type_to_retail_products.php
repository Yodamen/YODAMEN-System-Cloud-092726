<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('retail_products', 'stock_type')) {
            Schema::table('retail_products', function (Blueprint $table) {
                $table->string('stock_type', 30)
                    ->default('INVENTORY')
                    ->after('unit');
            });
        }

        if (! Schema::hasIndex('retail_products', ['stock_type'])) {
            Schema::table('retail_products', function (Blueprint $table) {
                $table->index('stock_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('retail_products', ['stock_type'])) {
            Schema::table('retail_products', function (Blueprint $table) {
                $table->dropIndex(['stock_type']);
            });
        }

        if (Schema::hasColumn('retail_products', 'stock_type')) {
            Schema::table('retail_products', function (Blueprint $table) {
                $table->dropColumn('stock_type');
            });
        }
    }
};
