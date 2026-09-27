<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('service_consumption_recipe_lines')) {
            return;
        }

        if (! Schema::hasColumn('service_consumption_recipe_lines', 'quantity_per_service')
            || ! Schema::hasColumn('service_consumption_recipe_lines', 'consumption_to_basic_factor_snapshot')
            || ! Schema::hasColumn('service_consumption_recipe_lines', 'basic_quantity_per_service')) {
            return;
        }

        DB::table('service_consumption_recipe_lines')
            ->orderBy('id')
            ->get(['id', 'quantity_per_service', 'consumption_to_basic_factor_snapshot'])
            ->each(function ($line): void {
                $factor = max(0.000001, (float) ($line->consumption_to_basic_factor_snapshot ?: 1));
                $basicQuantity = round((float) $line->quantity_per_service / $factor, 4);

                DB::table('service_consumption_recipe_lines')
                    ->where('id', $line->id)
                    ->update(['basic_quantity_per_service' => $basicQuantity]);
            });
    }

    public function down(): void
    {
        // This migration corrects stored derived quantities only. It is intentionally
        // non-reversible because the previous values used the wrong conversion direction.
    }
};
