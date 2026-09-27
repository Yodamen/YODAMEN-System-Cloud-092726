<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->change();
            $table->foreignId('target_booking_id')->nullable()->after('sale_id')->constrained('bookings')->cascadeOnUpdate()->restrictOnDelete();
            $table->index(['target_booking_id', 'target_type', 'status'], 'payment_applications_booking_target_index');
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropIndex('payment_applications_booking_target_index');
            $table->dropConstrainedForeignId('target_booking_id');
            $table->foreignId('sale_id')->nullable(false)->change();
        });
    }
};
