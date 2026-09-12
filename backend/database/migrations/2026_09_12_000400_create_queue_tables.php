<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('customer_type', 20);
            $table->date('queue_date');
            $table->timestamp('check_in_at');
            $table->string('source', 30)->index();
            $table->string('status', 30)->default('WAITING')->index();
            $table->timestamp('booking_created_at')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->time('scheduled_time')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'branch_id', 'queue_date', 'status'], 'queue_entries_daily_index');
            $table->index(['customer_id', 'queue_date']);
            $table->index(['source', 'queue_date', 'check_in_at']);
        });

        Schema::create('queue_service_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_entry_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('booking_service_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 80)->unique();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('service_name_snapshot');
            $table->string('source_type', 30)->default('NEW_SERVICE');
            $table->string('source_reference_code', 80)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('assigned_personnel_id')->nullable()->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('assigned_personnel_name_snapshot')->nullable();
            $table->string('status', 30)->default('PENDING')->index();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['queue_entry_id', 'sort_order']);
            $table->index(['assigned_personnel_id', 'status']);
        });

        Schema::create('queue_retail_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('queue_entry_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('booking_retail_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
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

            $table->index(['queue_entry_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_retail_lines');
        Schema::dropIfExists('queue_service_lines');
        Schema::dropIfExists('queue_entries');
    }
};
