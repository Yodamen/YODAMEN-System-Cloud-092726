<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedInteger('trial_uses')->default(0);
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('trial_counted')->default(false);
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->boolean('trial_counted')->default(false);
        });
        // Imported history does not consume the new trial. Later sales for old
        // bookings must not count those historical visits a second time.
        DB::table('bookings')->update(['trial_counted' => true]);
        DB::table('sales')->update(['trial_counted' => true]);
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn('trial_counted'));
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('trial_counted'));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('trial_uses'));
    }
};
