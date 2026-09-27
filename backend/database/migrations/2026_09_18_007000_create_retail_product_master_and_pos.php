<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retail_products')) {
            Schema::create('retail_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('sku', 100);
                $table->string('barcode', 120)->nullable();
                $table->string('name', 180);
                $table->string('category', 120)->nullable()->index();
                $table->string('brand', 120)->nullable();
                $table->string('unit', 40)->default('pc');
                $table->decimal('unit_cost', 12, 2)->default(0);
                $table->decimal('selling_price', 12, 2)->default(0);
                $table->unsignedInteger('reorder_point')->default(0);
                $table->boolean('commission_eligible')->default(true);
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'sku'], 'retail_products_business_sku_uq');
                $table->index(['business_id', 'status', 'name'], 'retail_products_business_status_name_idx');
            });
        }

        if (! Schema::hasTable('inventory_stocks')) {
            Schema::create('inventory_stocks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->integer('on_hand')->default(0);
                $table->integer('reserved')->default(0);
                $table->timestamps();

                $table->unique(['branch_id', 'retail_product_id'], 'inventory_stocks_branch_product_uq');
                $table->index(['business_id', 'branch_id'], 'inventory_stocks_business_branch_idx');
            });
        }

        if (! Schema::hasTable('inventory_movements')) {
            Schema::create('inventory_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('sale_id')->nullable()->constrained('sales')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('movement_type', 40)->index();
                $table->integer('quantity_change');
                $table->integer('quantity_before');
                $table->integer('quantity_after');
                $table->decimal('unit_cost_snapshot', 12, 2)->default(0);
                $table->string('reference_code', 120)->nullable();
                $table->text('reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('movement_at');
                $table->timestamps();

                $table->index(['business_id', 'branch_id', 'movement_at'], 'inventory_movements_business_branch_date_idx');
                $table->index(['retail_product_id', 'movement_at'], 'inventory_movements_product_date_idx');
            });
        }

        if (! Schema::hasColumn('sales', 'sale_channel')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('sale_channel', 30)->default('OPERATIONS')->after('customer_id')->index();
            });
        }

        if (! Schema::hasColumn('sale_items', 'retail_product_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->foreignId('retail_product_id')->nullable()->after('service_id')
                    ->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('sku_snapshot', 100)->nullable()->after('retail_product_id');
                $table->decimal('unit_cost_snapshot', 12, 2)->nullable()->after('sku_snapshot');
            });
        }

        if (! Schema::hasColumn('commission_rules', 'retail_product_id')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->foreignId('retail_product_id')->nullable()->after('service_category_reference_id')
                    ->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('retail_category', 120)->nullable()->after('retail_product_id');
                $table->index(['business_id', 'commission_scope', 'retail_product_id'], 'commission_rules_retail_product_idx');
                $table->index(['business_id', 'commission_scope', 'retail_category'], 'commission_rules_retail_category_idx');
            });
        }

        // Retail POS is a direct Sale and does not require a Booking, Visit, or known Customer.
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('visit_id')->nullable()->change();
            $table->foreignId('booking_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->change();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreignId('booking_id')->nullable()->change();
        });

        Schema::table('commission_earnings', function (Blueprint $table) {
            $table->foreignId('visit_id')->nullable()->change();
            $table->foreignId('booking_id')->nullable()->change();
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('commission_rules', 'retail_product_id')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->dropIndex('commission_rules_retail_product_idx');
                $table->dropIndex('commission_rules_retail_category_idx');
                $table->dropConstrainedForeignId('retail_product_id');
                $table->dropColumn('retail_category');
            });
        }

        if (Schema::hasColumn('sales', 'sale_channel')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->dropColumn('sale_channel');
            });
        }

        if (Schema::hasColumn('sale_items', 'retail_product_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('retail_product_id');
                $table->dropColumn(['sku_snapshot', 'unit_cost_snapshot']);
            });
        }

        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_stocks');
        Schema::dropIfExists('retail_products');
    }
};
