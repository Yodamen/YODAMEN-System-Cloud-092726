<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Service consumption can represent fractions of the Basic UOM
        // (example: 1 Vial = 8 ml, a service may consume 4 ml = 0.5 Vial).
        Schema::table('retail_products', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 4)->default(0)->change();
        });

        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->decimal('on_hand', 14, 4)->default(0)->change();
            $table->decimal('reserved', 14, 4)->default(0)->change();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->decimal('quantity_change', 14, 4)->change();
            $table->decimal('quantity_before', 14, 4)->change();
            $table->decimal('quantity_after', 14, 4)->change();
            $table->decimal('unit_cost_snapshot', 14, 4)->default(0)->change();
        });

        Schema::table('inventory_receipt_lines', function (Blueprint $table) {
            $table->decimal('quantity_received', 14, 4)->change();
            $table->decimal('unit_cost', 14, 4)->change();
        });

        Schema::table('inventory_count_lines', function (Blueprint $table) {
            $table->decimal('system_quantity_snapshot', 14, 4)->default(0)->change();
            $table->decimal('actual_quantity', 14, 4)->nullable()->change();
            $table->decimal('variance_quantity', 14, 4)->nullable()->change();
            $table->decimal('unit_cost_snapshot', 14, 4)->default(0)->change();
        });

        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->decimal('quantity_change', 14, 4)->change();
        });

        Schema::table('retail_products', function (Blueprint $table) {
            $table->decimal('reorder_point', 14, 4)->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost_snapshot', 14, 4)->nullable()->change();
        });

        if (! Schema::hasTable('service_consumptions')) {
            Schema::create('service_consumptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 40);
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('visit_id')->constrained('visits')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('visit_service_line_id')->constrained('visit_service_lines')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('service_completion_event_id')->constrained('service_completion_events')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('service_id')->constrained('services')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('service_name_snapshot', 180);
                $table->decimal('service_quantity', 14, 4)->default(1);
                $table->decimal('total_cost', 14, 2)->default(0);
                $table->string('status', 20)->default('POSTED')->index();
                $table->timestamp('posted_at');
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'code'], 'service_consumptions_business_code_uq');
                $table->unique('visit_service_line_id', 'service_consumptions_visit_line_uq');
                $table->unique('service_completion_event_id', 'service_consumptions_completion_event_uq');
                $table->index(['business_id', 'branch_id', 'posted_at'], 'service_consumptions_business_branch_date_idx');
            });
        }

        if (! Schema::hasTable('service_consumption_lines')) {
            Schema::create('service_consumption_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_consumption_id')->constrained('service_consumptions')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('inventory_movement_id')->nullable()->constrained('inventory_movements')->cascadeOnUpdate()->nullOnDelete();
                $table->string('item_name_snapshot', 180);
                $table->decimal('consumption_quantity', 14, 4);
                $table->string('consumption_uom_snapshot', 40);
                $table->decimal('consumption_to_basic_factor_snapshot', 14, 6)->default(1);
                $table->decimal('basic_quantity', 14, 4);
                $table->string('basic_uom_snapshot', 40);
                $table->decimal('weighted_average_basic_cost', 14, 4)->default(0);
                $table->decimal('cost_amount', 14, 2)->default(0);
                $table->decimal('quantity_before', 14, 4);
                $table->decimal('quantity_after', 14, 4);
                $table->timestamps();

                $table->unique(['service_consumption_id', 'retail_product_id'], 'service_consumption_lines_consumption_item_uq');
                $table->index(['retail_product_id', 'created_at'], 'service_consumption_lines_product_date_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('service_consumption_lines');
        Schema::dropIfExists('service_consumptions');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('unit_cost_snapshot', 12, 2)->nullable()->change();
        });
        Schema::table('retail_products', function (Blueprint $table) {
            $table->unsignedInteger('reorder_point')->default(0)->change();
        });
        Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
            $table->integer('quantity_change')->change();
        });
        Schema::table('inventory_count_lines', function (Blueprint $table) {
            $table->integer('system_quantity_snapshot')->default(0)->change();
            $table->integer('actual_quantity')->nullable()->change();
            $table->integer('variance_quantity')->nullable()->change();
            $table->decimal('unit_cost_snapshot', 14, 2)->default(0)->change();
        });
        Schema::table('inventory_receipt_lines', function (Blueprint $table) {
            $table->unsignedInteger('quantity_received')->change();
            $table->decimal('unit_cost', 14, 2)->change();
        });
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->integer('quantity_change')->change();
            $table->integer('quantity_before')->change();
            $table->integer('quantity_after')->change();
            $table->decimal('unit_cost_snapshot', 12, 2)->default(0)->change();
        });
        Schema::table('retail_products', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->default(0)->change();
        });
        Schema::table('inventory_stocks', function (Blueprint $table) {
            $table->integer('on_hand')->default(0)->change();
            $table->integer('reserved')->default(0)->change();
        });
    }
};
