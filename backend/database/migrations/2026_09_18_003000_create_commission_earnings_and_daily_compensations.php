<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->foreignId('sale_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sale_item_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('visit_service_line_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('work_date');
            $table->string('service_name_snapshot');
            $table->string('personnel_name_snapshot');
            $table->string('basis', 50)->default('STANDARD_SERVICE_PRICE');
            $table->decimal('basis_amount', 12, 2)->default(0);
            $table->string('calculation_type', 20);
            $table->decimal('rate_value', 12, 4)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->string('status', 30)->default('OPEN')->index();
            $table->text('reversal_reason')->nullable();
            $table->dateTime('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'work_date', 'status']);
            $table->index(['personnel_id', 'work_date', 'status']);
            $table->index(['sale_id', 'status']);
        });

        Schema::create('daily_compensations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('leave_request_id')->nullable()->constrained('leave_requests')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('work_date');
            $table->string('compensation_rule_snapshot', 80);
            $table->decimal('hourly_rate_snapshot', 12, 2)->default(0);
            $table->boolean('ot_entitled_snapshot')->default(false);
            $table->decimal('ot_multiplier_snapshot', 6, 3)->nullable();
            $table->decimal('guaranteed_daily_pay_snapshot', 12, 2)->default(0);
            $table->unsignedInteger('scheduled_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('ot_minutes')->default(0);
            $table->decimal('paid_leave_units', 6, 2)->default(0);
            $table->unsignedInteger('paid_leave_minutes')->default(0);
            $table->decimal('hourly_basic_pay', 12, 2)->default(0);
            $table->decimal('ot_pay', 12, 2)->default(0);
            $table->decimal('confirmed_commission', 12, 2)->default(0);
            $table->decimal('guaranteed_target', 12, 2)->default(0);
            $table->decimal('guaranteed_top_up', 12, 2)->default(0);
            $table->decimal('base_compensation_component', 12, 2)->default(0);
            $table->decimal('gross_daily_projection', 12, 2)->default(0);
            $table->string('status', 30)->default('OPEN')->index();
            $table->dateTime('recalculated_at');
            $table->timestamps();

            $table->unique(['personnel_id', 'work_date']);
            $table->index(['business_id', 'work_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_compensations');
        Schema::dropIfExists('commission_earnings');
    }
};
