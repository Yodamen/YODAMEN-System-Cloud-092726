<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 120);
            $table->boolean('is_paid')->default(false);
            $table->boolean('consumes_balance')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->unique(['business_id', 'name']);
        });

        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled_days', 8, 2)->default(0);
            $table->decimal('adjustment_days', 8, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['personnel_id', 'leave_type_id', 'year']);
            $table->index(['business_id', 'year']);
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 60)->unique();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('request_type', 20); // LEAVE | DAY_OFF
            $table->foreignId('leave_type_id')->nullable()->constrained('leave_types')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('duration_type', 30)->default('FULL_DAY'); // FULL_DAY | HALF_DAY_AM | HALF_DAY_PM
            $table->decimal('approved_units', 8, 2)->default(0);
            $table->text('reason');
            $table->string('status', 20)->default('PENDING')->index();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('submitted_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'status', 'start_date']);
            $table->index(['personnel_id', 'start_date', 'end_date']);
            $table->index(['leave_type_id', 'status']);
        });

        Schema::table('personnel_schedule_overrides', function (Blueprint $table) {
            $table->string('source_type', 40)->nullable()->after('notes');
            $table->unsignedBigInteger('source_reference_id')->nullable()->after('source_type');
            $table->index(['source_type', 'source_reference_id'], 'schedule_override_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('personnel_schedule_overrides', function (Blueprint $table) {
            $table->dropIndex('schedule_override_source_index');
            $table->dropColumn(['source_type', 'source_reference_id']);
        });

        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_types');
    }
};
