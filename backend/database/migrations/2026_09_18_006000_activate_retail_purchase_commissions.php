<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('booking_retail_lines') && ! Schema::hasColumn('booking_retail_lines', 'assigned_personnel_id')) {
            Schema::table('booking_retail_lines', function (Blueprint $table) {
                $table->foreignId('assigned_personnel_id')->nullable()->after('unit_price_snapshot')
                    ->constrained('personnel')->cascadeOnUpdate()->nullOnDelete();
                $table->string('assigned_personnel_name_snapshot')->nullable()->after('assigned_personnel_id');
            });
        }

        if (Schema::hasTable('queue_retail_lines') && ! Schema::hasColumn('queue_retail_lines', 'assigned_personnel_id')) {
            Schema::table('queue_retail_lines', function (Blueprint $table) {
                $table->foreignId('assigned_personnel_id')->nullable()->after('unit_price_snapshot')
                    ->constrained('personnel')->cascadeOnUpdate()->nullOnDelete();
                $table->string('assigned_personnel_name_snapshot')->nullable()->after('assigned_personnel_id');
            });
        }

        if (Schema::hasTable('visit_retail_lines') && ! Schema::hasColumn('visit_retail_lines', 'assigned_personnel_id')) {
            Schema::table('visit_retail_lines', function (Blueprint $table) {
                $table->foreignId('assigned_personnel_id')->nullable()->after('unit_price_snapshot')
                    ->constrained('personnel')->cascadeOnUpdate()->nullOnDelete();
                $table->string('assigned_personnel_name_snapshot')->nullable()->after('assigned_personnel_id');
            });
        }

        if (Schema::hasTable('commission_rules') && ! Schema::hasColumn('commission_rules', 'commission_scope')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                // Existing rules remain service-only so retail commission is never enabled accidentally.
                $table->string('commission_scope', 20)->default('SERVICE')->after('applies_to');
                $table->index(['business_id', 'commission_scope', 'status'], 'commission_rules_scope_status_idx');
            });
        }

        if (Schema::hasTable('commission_earnings')) {
            if (! Schema::hasColumn('commission_earnings', 'visit_retail_line_id')) {
                Schema::table('commission_earnings', function (Blueprint $table) {
                    $table->foreignId('visit_retail_line_id')->nullable()->after('visit_service_line_id')
                        ->constrained('visit_retail_lines')->cascadeOnUpdate()->restrictOnDelete();
                    $table->string('earning_type', 20)->default('SERVICE')->after('visit_retail_line_id');
                    $table->unique('visit_retail_line_id', 'commission_earnings_retail_line_uq');
                });
            }

            // Retail earnings do not have a Visit Service or Service master row.
            Schema::table('commission_earnings', function (Blueprint $table) {
                $table->foreignId('visit_service_line_id')->nullable()->change();
                $table->foreignId('service_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('commission_earnings') && Schema::hasColumn('commission_earnings', 'visit_retail_line_id')) {
            Schema::table('commission_earnings', function (Blueprint $table) {
                $table->dropUnique('commission_earnings_retail_line_uq');
                $table->dropConstrainedForeignId('visit_retail_line_id');
                $table->dropColumn('earning_type');
            });
        }

        if (Schema::hasTable('commission_rules') && Schema::hasColumn('commission_rules', 'commission_scope')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->dropIndex('commission_rules_scope_status_idx');
                $table->dropColumn('commission_scope');
            });
        }

        foreach (['booking_retail_lines', 'queue_retail_lines', 'visit_retail_lines'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'assigned_personnel_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('assigned_personnel_id');
                    $table->dropColumn('assigned_personnel_name_snapshot');
                });
            }
        }
    }
};
