<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 80);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'name']);
        });

        Schema::create('reference_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reference_group_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 100);
            $table->string('display_value', 150);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('active')->default(true)->index();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['reference_group_id', 'code']);
            $table->unique(['reference_group_id', 'display_value']);
            $table->index(['reference_group_id', 'active', 'sort_order']);
        });

        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('service_category_reference_id')->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_count_reference_id')->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('number_of_sessions')->default(1);
            $table->unsignedInteger('duration_minutes')->default(0);
            $table->text('description')->nullable();
            $table->foreignId('status_reference_id')->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'name']);
            $table->index(['business_id', 'service_category_reference_id']);
            $table->index(['business_id', 'status_reference_id']);
        });

        Schema::create('service_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('scope', 50)->default('ALL');
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('standard_price', 12, 2);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 20)->default('SCHEDULED')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'scope', 'effective_from']);
            $table->index(['service_id', 'scope', 'status']);
            $table->index(['business_id', 'effective_from']);
        });

        Schema::create('personnel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('mobile_number', 50)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('position_reference_id')->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('employment_type_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_type_reference_id')->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->boolean('profit_sharing_eligible')->default(false);
            $table->decimal('profit_sharing_percentage', 7, 4)->default(0);
            $table->date('profit_sharing_effective_from')->nullable();
            $table->date('profit_sharing_effective_until')->nullable();
            $table->decimal('hourly_rate', 12, 2)->default(0);
            $table->boolean('ot_entitled')->default(false);
            $table->decimal('ot_multiplier', 6, 3)->nullable();
            $table->foreignId('compensation_rule_reference_id')->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('guaranteed_daily_pay', 12, 2)->default(0);
            $table->boolean('can_perform_services')->default(false);
            $table->boolean('commission_eligible')->default(false);
            $table->date('hire_date')->nullable();
            $table->foreignId('linked_user_id')->nullable()->unique()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'branch_id']);
            $table->index(['business_id', 'position_reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel');
        Schema::dropIfExists('service_prices');
        Schema::dropIfExists('services');
        Schema::dropIfExists('reference_values');
        Schema::dropIfExists('reference_groups');
    }
};
