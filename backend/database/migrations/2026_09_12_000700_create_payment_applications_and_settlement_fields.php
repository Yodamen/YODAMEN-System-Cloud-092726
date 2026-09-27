<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('applied_amount', 12, 2)->default(0)->after('total_paid');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('applied_amount', 12, 2)->default(0)->after('line_total');
            $table->decimal('balance_amount', 12, 2)->default(0)->after('applied_amount');
            $table->timestamp('financial_finalized_at')->nullable()->after('status');
        });

        DB::table('sale_items')->update([
            'balance_amount' => DB::raw('line_total'),
        ]);

        Schema::create('payment_applications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 70)->unique();
            $table->string('application_key', 190)->unique();
            $table->foreignId('payment_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('target_type', 40)->index();
            $table->foreignId('target_sale_item_id')->nullable()->constrained('sale_items')->cascadeOnUpdate()->restrictOnDelete();
            $table->unsignedBigInteger('package_enrollment_id')->nullable()->index();
            $table->foreignId('source_sale_id')->nullable()->constrained('sales')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->unsignedInteger('allocation_order')->default(0);
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->json('target_snapshot')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();

            $table->index(['sale_id', 'target_type', 'status']);
            $table->index(['payment_id', 'status']);
            $table->index(['target_sale_item_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_applications');

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['applied_amount', 'balance_amount', 'financial_finalized_at']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('applied_amount');
        });
    }
};
