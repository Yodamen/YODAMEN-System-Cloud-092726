<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_type_criteria')) {
            return;
        }

        if (! Schema::hasColumn('customer_type_criteria', 'color')) {
            Schema::table('customer_type_criteria', function (Blueprint $table) {
                $table->string('color', 7)->default('#343434')->after('name');
            });
        }

        $palette = ['#9A9388', '#665C52', '#8A6A3E', '#242424', '#4B5563', '#6B4F3A'];

        DB::table('customer_type_criteria')
            ->select('business_id')
            ->distinct()
            ->pluck('business_id')
            ->each(function ($businessId) use ($palette) {
                DB::table('customer_type_criteria')
                    ->where('business_id', $businessId)
                    ->orderBy('min_completed_visits')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'color'])
                    ->values()
                    ->each(function ($row, int $index) use ($palette) {
                        $current = strtoupper(trim((string) ($row->color ?? '')));
                        if ($current !== '' && $current !== '#343434') {
                            return;
                        }

                        DB::table('customer_type_criteria')
                            ->where('id', $row->id)
                            ->update([
                                'color' => $palette[$index % count($palette)],
                                'updated_at' => now(),
                            ]);
                    });
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('customer_type_criteria') && Schema::hasColumn('customer_type_criteria', 'color')) {
            Schema::table('customer_type_criteria', function (Blueprint $table) {
                $table->dropColumn('color');
            });
        }
    }
};
