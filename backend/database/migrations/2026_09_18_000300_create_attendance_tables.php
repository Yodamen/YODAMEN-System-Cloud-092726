<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('attendance_date');
            $table->string('source', 30)->default('SELF_SERVICE');
            $table->string('workflow_status', 30)->default('WORKING');
            $table->string('attendance_status', 30)->default('PENDING');
            $table->dateTime('time_in_at');
            $table->dateTime('time_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('ot_minutes')->default(0);
            $table->dateTime('original_time_in_at')->nullable();
            $table->dateTime('original_time_out_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->dateTime('adjusted_at')->nullable();
            $table->dateTime('payroll_locked_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'personnel_id', 'attendance_date'], 'uq_attendance_personnel_day');
            $table->index(['business_id', 'attendance_date']);
            $table->index(['business_id', 'workflow_status']);
            $table->index(['personnel_id', 'attendance_date']);
        });

        Schema::create('attendance_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_id')->constrained('attendances')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('proof_type', 20);
            $table->string('disk', 50)->default('local');
            $table->string('path', 1000);
            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->dateTime('captured_at');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['attendance_id', 'proof_type'], 'uq_attendance_proof_type');
            $table->index(['business_id', 'proof_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_proofs');
        Schema::dropIfExists('attendances');
    }
};
