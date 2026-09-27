<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('z_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 20)->default('CLOSED')->index();
            $table->json('snapshot');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'branch_id', 'business_date'], 'z_readings_business_branch_date_uq');
            $table->index(['business_id', 'business_date'], 'z_readings_business_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('z_readings');
    }
};
