<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->foreignId('queue_entry_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('customer_type', 20);
            $table->date('visit_date');
            $table->timestamp('check_in_at');
            $table->timestamp('service_start_at');
            $table->timestamp('check_out_at')->nullable();
            $table->string('status', 30)->default('IN_SERVICE')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'branch_id', 'visit_date', 'status'], 'visits_daily_status_index');
            $table->index(['customer_id', 'visit_date']);
            $table->index(['booking_id', 'status']);
        });

        Schema::create('visit_service_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('queue_service_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_service_line_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 80)->unique();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('service_name_snapshot');
            $table->string('source_type', 30)->default('NEW_SERVICE');
            $table->string('source_reference_code', 80)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('assigned_personnel_id')->nullable()->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('assigned_personnel_name_snapshot')->nullable();
            $table->string('status', 30)->default('IN_PROGRESS')->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'sort_order']);
            $table->index(['assigned_personnel_id', 'status']);
            $table->index(['service_id', 'status']);
        });

        Schema::create('visit_retail_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('queue_retail_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_retail_line_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 80)->unique();
            $table->string('legacy_retail_item_code', 80)->nullable();
            $table->string('item_name_snapshot');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price_snapshot', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'sort_order']);
        });

        Schema::create('service_completion_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->foreignId('visit_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('visit_service_line_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('assigned_personnel_id')->nullable()->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('service_name_snapshot');
            $table->string('assigned_personnel_name_snapshot')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('completed_at');
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'completed_at']);
            $table->index(['service_id', 'completed_at']);
            $table->index(['assigned_personnel_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_completion_events');
        Schema::dropIfExists('visit_retail_lines');
        Schema::dropIfExists('visit_service_lines');
        Schema::dropIfExists('visits');
    }
};
