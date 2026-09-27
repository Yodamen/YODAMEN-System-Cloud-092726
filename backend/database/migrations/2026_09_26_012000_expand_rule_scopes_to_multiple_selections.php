<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commission_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('commission_rules', 'personnel_ids')) $table->json('personnel_ids')->nullable()->after('personnel_id');
            if (! Schema::hasColumn('commission_rules', 'service_ids')) $table->json('service_ids')->nullable()->after('service_id');
            if (! Schema::hasColumn('commission_rules', 'service_category_reference_ids')) $table->json('service_category_reference_ids')->nullable()->after('service_category_reference_id');
        });

        Schema::table('discount_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('discount_rules', 'personnel_ids')) $table->json('personnel_ids')->nullable()->after('branch_id');
            if (! Schema::hasColumn('discount_rules', 'service_ids')) $table->json('service_ids')->nullable()->after('service_id');
            if (! Schema::hasColumn('discount_rules', 'service_category_reference_ids')) $table->json('service_category_reference_ids')->nullable()->after('service_category_reference_id');
        });

        Schema::table('incentive_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('incentive_rules', 'personnel_ids')) $table->json('personnel_ids')->nullable()->after('personnel_id');
            if (! Schema::hasColumn('incentive_rules', 'service_ids')) $table->json('service_ids')->nullable()->after('personnel_ids');
            if (! Schema::hasColumn('incentive_rules', 'service_category_reference_ids')) $table->json('service_category_reference_ids')->nullable()->after('service_ids');
        });

        DB::table('commission_rules')->whereNotNull('personnel_id')->orderBy('id')->each(function ($row) {
            DB::table('commission_rules')->where('id', $row->id)->update(['personnel_ids' => json_encode([(int) $row->personnel_id])]);
        });
        DB::table('commission_rules')->whereNotNull('service_id')->orderBy('id')->each(function ($row) {
            DB::table('commission_rules')->where('id', $row->id)->update(['service_ids' => json_encode([(int) $row->service_id])]);
        });
        DB::table('commission_rules')->whereNotNull('service_category_reference_id')->orderBy('id')->each(function ($row) {
            DB::table('commission_rules')->where('id', $row->id)->update(['service_category_reference_ids' => json_encode([(int) $row->service_category_reference_id])]);
        });

        DB::table('discount_rules')->whereNotNull('service_id')->orderBy('id')->each(function ($row) {
            DB::table('discount_rules')->where('id', $row->id)->update(['service_ids' => json_encode([(int) $row->service_id])]);
        });
        DB::table('discount_rules')->whereNotNull('service_category_reference_id')->orderBy('id')->each(function ($row) {
            DB::table('discount_rules')->where('id', $row->id)->update(['service_category_reference_ids' => json_encode([(int) $row->service_category_reference_id])]);
        });

        DB::table('incentive_rules')->whereNotNull('personnel_id')->orderBy('id')->each(function ($row) {
            DB::table('incentive_rules')->where('id', $row->id)->update(['personnel_ids' => json_encode([(int) $row->personnel_id])]);
        });
    }

    public function down(): void
    {
        Schema::table('incentive_rules', function (Blueprint $table) {
            foreach (['service_category_reference_ids', 'service_ids', 'personnel_ids'] as $column) {
                if (Schema::hasColumn('incentive_rules', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('discount_rules', function (Blueprint $table) {
            foreach (['service_category_reference_ids', 'service_ids', 'personnel_ids'] as $column) {
                if (Schema::hasColumn('discount_rules', $column)) $table->dropColumn($column);
            }
        });
        Schema::table('commission_rules', function (Blueprint $table) {
            foreach (['service_category_reference_ids', 'service_ids', 'personnel_ids'] as $column) {
                if (Schema::hasColumn('commission_rules', $column)) $table->dropColumn($column);
            }
        });
    }
};
