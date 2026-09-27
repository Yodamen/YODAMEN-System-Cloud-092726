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
                $table->string('stock_type', 20)->default('INVENTORY')->after('unit')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('retail_products', 'stock_type')) {
            Schema::table('retail_products', fn (Blueprint $table) => $table->dropColumn('stock_type'));
        }
    }
};
