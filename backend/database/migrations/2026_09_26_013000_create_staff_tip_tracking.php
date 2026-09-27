<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->foreignId('sale_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('payment_method_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('received_cash_account_id')->nullable()->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('received_method_snapshot', 150)->nullable();
            $table->string('received_reference', 180)->nullable();
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignId('payout_account_id')->nullable()->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('payout_method_snapshot', 180)->nullable();
            $table->string('payout_reference', 180)->nullable();
            $table->date('payout_date')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'personnel_id', 'status']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_tips');
    }
};
