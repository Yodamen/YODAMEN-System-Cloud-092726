<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_branding_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->unique()->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('system_name')->default('YODAMEN Management System');
            $table->string('business_display_name')->nullable();
            $table->string('subtitle')->nullable();
            $table->text('logo_url')->nullable();
            $table->string('accent_color', 7)->default('#c8a64b');
            $table->string('login_label')->default('Operating System');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_branding_settings');
    }
};
