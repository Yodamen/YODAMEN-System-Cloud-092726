<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_counts', 'stock_type')) {
                $table->string('stock_type', 30)->nullable()->after('scope_type')->index();
            }
            if (! Schema::hasColumn('inventory_counts', 'categories_json')) {
                $table->text('categories_json')->nullable()->after('category');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_counts', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_counts', 'categories_json')) $table->dropColumn('categories_json');
            if (Schema::hasColumn('inventory_counts', 'stock_type')) $table->dropColumn('stock_type');
        });
    }
};
