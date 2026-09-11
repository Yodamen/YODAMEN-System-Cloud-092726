<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('mobile_number', 50);
            $table->string('mobile_normalized', 30);
            $table->string('email')->nullable();
            $table->date('birthday');
            $table->foreignId('gender_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->text('address')->nullable();
            $table->foreignId('lead_source_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('registration_date');
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'mobile_normalized']);
            $table->index(['business_id', 'status']);
            $table->index(['business_id', 'registration_date']);
            $table->index(['business_id', 'last_name', 'first_name']);
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('customer_type', 20);
            $table->date('booking_date');
            $table->time('booking_time');
            $table->string('booking_source', 30)->default('RESERVATION')->index();
            $table->string('status', 30)->default('BOOKED')->index();
            $table->text('notes')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'booking_date', 'status']);
            $table->index(['branch_id', 'booking_date', 'booking_time']);
            $table->index(['customer_id', 'booking_date']);
        });

        Schema::create('booking_service_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('code', 80)->unique();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('service_name_snapshot');
            $table->string('source_type', 30)->default('NEW_SERVICE');
            $table->string('source_reference_code', 80)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->foreignId('assigned_personnel_id')->nullable()->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['booking_id', 'sort_order']);
            $table->index(['service_id', 'source_type']);
            $table->index(['assigned_personnel_id', 'booking_id']);
        });

        Schema::create('booking_retail_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
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

            $table->index(['booking_id', 'sort_order']);
        });

        Schema::create('booking_rebookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->date('old_booking_date');
            $table->time('old_booking_time');
            $table->date('new_booking_date');
            $table->time('new_booking_time');
            $table->text('reason')->nullable();
            $table->foreignId('rebooked_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('rebooked_at');
            $table->timestamps();

            $table->index(['booking_id', 'rebooked_at']);
            $table->index(['business_id', 'rebooked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_rebookings');
        Schema::dropIfExists('booking_retail_lines');
        Schema::dropIfExists('booking_service_lines');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('customers');
    }
};
