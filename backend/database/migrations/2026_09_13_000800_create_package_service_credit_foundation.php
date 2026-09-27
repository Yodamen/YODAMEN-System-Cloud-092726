<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('service_name_snapshot');
            $table->unsignedInteger('total_sessions');
            $table->decimal('package_price_snapshot', 12, 2)->default(0);
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('booking_service_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('queue_entry_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('queue_service_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('visit_service_line_id')->nullable()->unique()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('origin_sale_id')->nullable()->constrained('sales')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('origin_sale_item_id')->nullable()->unique()->constrained('sale_items')->cascadeOnUpdate()->nullOnDelete();
            $table->unsignedInteger('sessions_completed_before_activation')->default(0);
            $table->string('status', 30)->default('PENDING_CHECKOUT')->index();
            $table->date('expiry_date')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'status']);
            $table->index(['business_id', 'service_id', 'status']);
        });

        Schema::create('service_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->foreignId('package_enrollment_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('service_name_snapshot');
            $table->unsignedInteger('sessions_purchased');
            $table->unsignedInteger('sessions_used')->default(0);
            $table->unsignedInteger('sessions_remaining')->default(0);
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->date('expiry_date')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'customer_id', 'status']);
            $table->index(['customer_id', 'service_id', 'status']);
        });

        Schema::create('service_credit_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->foreignId('service_credit_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('package_enrollment_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('visit_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('visit_service_line_id')->unique()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedInteger('sessions_used')->default(1);
            $table->timestamp('usage_date');
            $table->foreignId('processed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['service_credit_id', 'usage_date']);
            $table->index(['package_enrollment_id', 'usage_date']);
        });

        Schema::create('service_credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->string('movement_key', 190)->unique();
            $table->foreignId('service_credit_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('package_enrollment_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('movement_type', 40)->index();
            $table->unsignedInteger('sessions');
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('queue_entry_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('visit_service_line_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->cascadeOnUpdate()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['package_enrollment_id', 'movement_type']);
            $table->index(['service_credit_id', 'movement_type']);
        });

        Schema::table('booking_service_lines', function (Blueprint $table) {
            $table->foreignId('package_enrollment_id')->nullable()->after('source_reference_code')->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('service_credit_id')->nullable()->after('package_enrollment_id')->constrained()->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('queue_service_lines', function (Blueprint $table) {
            $table->foreignId('package_enrollment_id')->nullable()->after('source_reference_code')->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('service_credit_id')->nullable()->after('package_enrollment_id')->constrained()->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('visit_service_lines', function (Blueprint $table) {
            $table->foreignId('package_enrollment_id')->nullable()->after('source_reference_code')->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('service_credit_id')->nullable()->after('package_enrollment_id')->constrained()->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('service_completion_events', function (Blueprint $table) {
            $table->string('service_source', 30)->default('NEW_SERVICE')->after('service_name_snapshot');
            $table->foreignId('package_enrollment_id')->nullable()->after('service_source')->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('service_credit_id')->nullable()->after('package_enrollment_id')->constrained()->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('package_enrollment_id')->nullable()->after('source_reference_code')->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('service_credit_id')->nullable()->after('package_enrollment_id')->constrained()->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::table('payment_applications', function (Blueprint $table) {
            $table->foreign('package_enrollment_id')->references('id')->on('package_enrollments')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropForeign(['package_enrollment_id']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_credit_id');
            $table->dropConstrainedForeignId('package_enrollment_id');
        });

        Schema::table('service_completion_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_credit_id');
            $table->dropConstrainedForeignId('package_enrollment_id');
            $table->dropColumn('service_source');
        });

        Schema::table('visit_service_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_credit_id');
            $table->dropConstrainedForeignId('package_enrollment_id');
        });

        Schema::table('queue_service_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_credit_id');
            $table->dropConstrainedForeignId('package_enrollment_id');
        });

        Schema::table('booking_service_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_credit_id');
            $table->dropConstrainedForeignId('package_enrollment_id');
        });

        Schema::dropIfExists('service_credit_ledger');
        Schema::dropIfExists('service_credit_usages');
        Schema::dropIfExists('service_credits');
        Schema::dropIfExists('package_enrollments');
    }
};
