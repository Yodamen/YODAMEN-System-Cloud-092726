<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_payment_splits')) {
            Schema::create('payroll_payment_splits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('funding_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->string('reference', 160)->nullable();
                $table->unsignedTinyInteger('sort_order')->default(1);
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['payroll_run_id', 'funding_account_id'], 'payroll_split_run_funding_unique');
                $table->index(['business_id', 'payroll_run_id'], 'payroll_split_business_run_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_payment_splits');
    }
};
