<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh test databases already receive these columns as nullable from the
        // corrected original migration. Existing MySQL development databases need
        // this compatibility migration because Phase 2A was already applied there.
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('mobile_number', 50)->nullable()->change();
            $table->string('mobile_normalized', 30)->nullable()->change();
            $table->date('birthday')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->string('mobile_number', 50)->nullable(false)->change();
            $table->string('mobile_normalized', 30)->nullable(false)->change();
            $table->date('birthday')->nullable(false)->change();
        });
    }
};
