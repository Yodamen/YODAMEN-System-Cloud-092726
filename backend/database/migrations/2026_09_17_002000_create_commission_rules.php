<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commission_rules')) {
            return;
        }

        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->string('rule_name', 160);

            $table->foreignId('branch_id')->nullable()->constrained('branches')->cascadeOnUpdate()->nullOnDelete();
            $table->string('applies_to', 20)->default('ALL');
            $table->foreignId('personnel_id')->nullable()->constrained('personnel')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('position_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->nullOnDelete();

            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_category_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->nullOnDelete();

            $table->string('calculation_type', 30)->default('PERCENTAGE');
            // Retained as a compatibility mirror for older code/data. New logic uses rate_value.
            $table->decimal('rate_percentage', 7, 4)->nullable();
            $table->decimal('rate_value', 12, 4);
            $table->string('basis', 50)->default('STANDARD_SERVICE_PRICE');

            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->integer('priority')->default(0);
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'applies_to', 'status'], 'commission_rules_business_applies_status_idx');
            $table->index(['business_id', 'personnel_id', 'status'], 'commission_rules_business_personnel_status_idx');
            $table->index(['business_id', 'position_reference_id', 'status'], 'commission_rules_business_position_status_idx');
            $table->index(['business_id', 'service_category_reference_id', 'status'], 'commission_rules_business_category_status_idx');
            $table->index(['business_id', 'branch_id', 'status'], 'commission_rules_business_branch_status_idx');
            $table->index(['business_id', 'service_id', 'status']);
            $table->index(['business_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_rules');
    }
};
