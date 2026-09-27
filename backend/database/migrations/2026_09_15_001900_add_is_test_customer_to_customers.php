<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_test_customer')->default(false)->after('status');
            $table->index(['business_id', 'is_test_customer'], 'customers_business_test_index');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_business_test_index');
            $table->dropColumn('is_test_customer');
        });
    }
};
