<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_work_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // ISO: Monday=1 ... Sunday=7
            $table->boolean('is_working_day')->default(false);
            $table->unsignedSmallInteger('expected_minutes')->default(0);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['personnel_id', 'day_of_week']);
            $table->index(['business_id', 'personnel_id']);
        });

        Schema::create('personnel_schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->cascadeOnDelete();
            $table->date('schedule_date');
            $table->string('schedule_type', 20); // WORKDAY | DAY_OFF
            $table->unsignedSmallInteger('expected_minutes')->default(0);
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->unique(['personnel_id', 'schedule_date']);
            $table->index(['business_id', 'schedule_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnel_schedule_overrides');
        Schema::dropIfExists('personnel_work_schedules');
    }
};
