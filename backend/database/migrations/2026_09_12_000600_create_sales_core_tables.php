<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->foreignId('visit_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->timestamp('sale_date');
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('advance_payments', 12, 2)->default(0);
            $table->decimal('checkout_payments', 12, 2)->default(0);
            $table->decimal('total_paid', 12, 2)->default(0);
            $table->decimal('outstanding_balance', 12, 2)->default(0);
            $table->string('status', 30)->default('FOR_CHECKOUT')->index();
            $table->timestamp('financial_finalized_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'branch_id', 'sale_date']);
            $table->index(['business_id', 'status', 'sale_date']);
            $table->index(['booking_id', 'status']);
            $table->index(['customer_id', 'sale_date']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 90)->unique();
            $table->string('source_key', 120)->unique();
            $table->foreignId('visit_service_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('visit_retail_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_personnel_id')->nullable()->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('assigned_personnel_name_snapshot')->nullable();
            $table->string('item_type', 30)->index();
            $table->string('item_name_snapshot');
            $table->string('service_source', 30)->nullable();
            $table->string('source_reference_code', 100)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->string('status', 30)->default('FOR_CHECKOUT')->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['sale_id', 'sort_order']);
            $table->index(['sale_id', 'item_type', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('payment_type', 30)->index();
            $table->decimal('amount', 12, 2);
            $table->foreignId('payment_method_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('payment_method_snapshot')->nullable();
            $table->string('reference_no', 150)->nullable();
            $table->string('bank_account', 150)->nullable();
            $table->timestamp('payment_date');
            $table->string('status', 20)->default('RECEIVED')->index();
            $table->string('idempotency_key', 190)->nullable()->unique();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'payment_type', 'status']);
            $table->index(['sale_id', 'payment_type', 'status']);
            $table->index(['customer_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
