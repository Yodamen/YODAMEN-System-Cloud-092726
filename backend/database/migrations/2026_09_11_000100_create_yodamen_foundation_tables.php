<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('currency', 3)->default('PHP');
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('short_code', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('timezone', 64)->nullable();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_super_admin')->default(false)->index();
            $table->boolean('is_system')->default(false)->index();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 150)->unique();
            $table->string('module', 100)->index();
            $table->string('action', 50)->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['module', 'action']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'permission_id']);
        });

        Schema::create('code_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('sequence_key', 100)->unique();
            $table->string('prefix', 30);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('pad_length')->default(4);
            $table->timestamps();
        });

        Schema::create('idempotency_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('scope', 150);
            $table->string('idempotency_key', 120);
            $table->char('request_hash', 64);
            $table->string('status', 20)->default('PROCESSING')->index();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['scope', 'idempotency_key']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('module', 100)->index();
            $table->string('entity_type', 150)->nullable()->index();
            $table->string('entity_id', 100)->nullable()->index();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('request_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('idempotency_requests');
        Schema::dropIfExists('code_sequences');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('businesses');
    }
};
