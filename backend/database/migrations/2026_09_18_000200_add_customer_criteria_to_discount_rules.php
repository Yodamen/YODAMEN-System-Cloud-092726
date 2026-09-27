<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->decimal('min_lifetime_value', 12, 2)->nullable()->after('customer_type');
            $table->decimal('max_lifetime_value', 12, 2)->nullable()->after('min_lifetime_value');
            $table->string('last_visit_operator', 30)->nullable()->after('max_lifetime_value');
            $table->unsignedInteger('last_visit_days_min')->nullable()->after('last_visit_operator');
            $table->unsignedInteger('last_visit_days_max')->nullable()->after('last_visit_days_min');
        });
    }

    public function down(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->dropColumn([
                'min_lifetime_value',
                'max_lifetime_value',
                'last_visit_operator',
                'last_visit_days_min',
                'last_visit_days_max',
            ]);
        });
    }
};
