<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supporting_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('businesses')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('module', 80);
            $table->string('entity_type', 80)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('purpose', 120)->nullable();
            $table->uuid('upload_token')->unique();
            $table->string('state', 20)->default('TEMP');
            $table->string('disk', 40)->default('local');
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 180)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->timestamp('attached_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'module', 'state'], 'supporting_docs_business_module_state_idx');
            $table->index(['business_id', 'entity_type', 'entity_id'], 'supporting_docs_business_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supporting_documents');
    }
};
