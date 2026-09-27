<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_products', function (Blueprint $table) {
            if (! Schema::hasColumn('retail_products', 'purchase_uom_cost')) {
                $table->decimal('purchase_uom_cost', 14, 4)->default(0)->after('purchase_to_basic_factor');
            }
            if (! Schema::hasColumn('retail_products', 'basic_uom_cost')) {
                $table->decimal('basic_uom_cost', 14, 4)->default(0)->after('purchase_uom_cost');
            }
            if (! Schema::hasColumn('retail_products', 'selling_uom')) {
                $table->string('selling_uom', 40)->nullable()->after('basic_uom_cost');
            }
            if (! Schema::hasColumn('retail_products', 'selling_to_basic_factor')) {
                $table->decimal('selling_to_basic_factor', 14, 6)->default(1)->after('selling_uom');
            }
            if (! Schema::hasColumn('retail_products', 'consumption_uom')) {
                $table->string('consumption_uom', 40)->nullable()->after('selling_to_basic_factor');
            }
            if (! Schema::hasColumn('retail_products', 'consumption_to_basic_factor')) {
                $table->decimal('consumption_to_basic_factor', 14, 6)->default(1)->after('consumption_uom');
            }
        });

        DB::table('retail_products')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $factor = max(0.000001, (float) ($row->purchase_to_basic_factor ?? 1));
                $basicCost = (float) ($row->unit_cost ?? 0);
                DB::table('retail_products')->where('id', $row->id)->update([
                    'purchase_uom_cost' => round($basicCost * $factor, 4),
                    'basic_uom_cost' => round($basicCost, 4),
                    'selling_uom' => $row->unit ?: 'pc',
                    'selling_to_basic_factor' => 1,
                    'consumption_uom' => $row->unit ?: 'pc',
                    'consumption_to_basic_factor' => 1,
                ]);
            }
        });


        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'selling_uom_snapshot')) {
                $table->string('selling_uom_snapshot', 40)->nullable()->after('unit_cost_snapshot');
            }
            if (! Schema::hasColumn('sale_items', 'selling_to_basic_factor_snapshot')) {
                $table->decimal('selling_to_basic_factor_snapshot', 14, 6)->default(1)->after('selling_uom_snapshot');
            }
            if (! Schema::hasColumn('sale_items', 'basic_quantity_snapshot')) {
                $table->decimal('basic_quantity_snapshot', 14, 4)->nullable()->after('selling_to_basic_factor_snapshot');
            }
        });

        DB::table('sale_items')->whereNotNull('retail_product_id')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $product = DB::table('retail_products')->where('id', $row->retail_product_id)->first();
                DB::table('sale_items')->where('id', $row->id)->update([
                    'selling_uom_snapshot' => $product?->selling_uom ?: $product?->unit,
                    'selling_to_basic_factor_snapshot' => (float) ($product?->selling_to_basic_factor ?: 1),
                    'basic_quantity_snapshot' => (float) $row->quantity * (float) ($product?->selling_to_basic_factor ?: 1),
                ]);
            }
        });

        Schema::table('service_consumption_recipe_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('service_consumption_recipe_lines', 'consumption_uom_snapshot')) {
                $table->string('consumption_uom_snapshot', 40)->nullable()->after('quantity_per_service');
            }
            if (! Schema::hasColumn('service_consumption_recipe_lines', 'consumption_to_basic_factor_snapshot')) {
                $table->decimal('consumption_to_basic_factor_snapshot', 14, 6)->default(1)->after('consumption_uom_snapshot');
            }
            if (! Schema::hasColumn('service_consumption_recipe_lines', 'basic_quantity_per_service')) {
                $table->decimal('basic_quantity_per_service', 14, 4)->default(0)->after('consumption_to_basic_factor_snapshot');
            }
        });

        DB::table('service_consumption_recipe_lines')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                DB::table('service_consumption_recipe_lines')->where('id', $row->id)->update([
                    'consumption_uom_snapshot' => $row->basic_uom_snapshot,
                    'consumption_to_basic_factor_snapshot' => 1,
                    'basic_quantity_per_service' => $row->quantity_per_service,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('service_consumption_recipe_lines', function (Blueprint $table) {
            foreach (['basic_quantity_per_service', 'consumption_to_basic_factor_snapshot', 'consumption_uom_snapshot'] as $column) {
                if (Schema::hasColumn('service_consumption_recipe_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sale_items', function (Blueprint $table) {
            foreach (['basic_quantity_snapshot', 'selling_to_basic_factor_snapshot', 'selling_uom_snapshot'] as $column) {
                if (Schema::hasColumn('sale_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('retail_products', function (Blueprint $table) {
            foreach (['consumption_to_basic_factor', 'consumption_uom', 'selling_to_basic_factor', 'selling_uom', 'basic_uom_cost', 'purchase_uom_cost'] as $column) {
                if (Schema::hasColumn('retail_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
