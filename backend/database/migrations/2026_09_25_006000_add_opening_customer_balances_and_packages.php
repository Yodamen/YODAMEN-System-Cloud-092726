<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_opening_balances', function (Blueprint $table) {
            $table->json('receivables_snapshot')->nullable()->after('inventory_snapshot');
            $table->json('packages_snapshot')->nullable()->after('receivables_snapshot');
            $table->decimal('receivables_total', 16, 2)->default(0)->after('inventory_total');
            $table->decimal('deferred_package_total', 16, 2)->default(0)->after('receivables_total');
        });

        Schema::table('package_enrollments', function (Blueprint $table) {
            $table->foreignId('opening_balance_id')->nullable()->after('origin_sale_item_id')
                ->constrained('accounting_opening_balances')->cascadeOnUpdate()->nullOnDelete();
        });

        Schema::create('opening_customer_receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('opening_balance_id')->constrained('accounting_opening_balances')->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('package_enrollment_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->string('balance_type', 30)->default('GENERAL')->index();
            $table->date('cutover_date')->index();
            $table->string('reference', 150)->nullable();
            $table->decimal('original_amount', 16, 2);
            $table->decimal('remaining_balance', 16, 2);
            $table->string('status', 20)->default('OPEN')->index();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'branch_id', 'status'], 'opening_ar_business_branch_status_idx');
            $table->index(['business_id', 'customer_id', 'status'], 'opening_ar_business_customer_status_idx');
        });

        Schema::table('payment_applications', function (Blueprint $table) {
            $table->foreignId('opening_customer_receivable_id')->nullable()->after('package_enrollment_id')
                ->constrained('opening_customer_receivables')->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payment_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_customer_receivable_id');
        });

        Schema::dropIfExists('opening_customer_receivables');

        Schema::table('package_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_balance_id');
        });

        Schema::table('accounting_opening_balances', function (Blueprint $table) {
            $table->dropColumn(['receivables_snapshot', 'packages_snapshot', 'receivables_total', 'deferred_package_total']);
        });
    }
};
