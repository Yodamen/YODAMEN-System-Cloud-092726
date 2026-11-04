<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->uuid('license_installation_id')->nullable()->unique();
            $table->text('license_token')->nullable();
            $table->unsignedBigInteger('license_revision')->default(0);
        });

        DB::table('businesses')->orderBy('id')->eachById(function ($business) {
            DB::table('businesses')->where('id', $business->id)->update([
                'license_installation_id' => (string) Str::uuid(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['license_installation_id']);
            $table->dropColumn(['license_installation_id', 'license_token', 'license_revision']);
        });
    }
};
