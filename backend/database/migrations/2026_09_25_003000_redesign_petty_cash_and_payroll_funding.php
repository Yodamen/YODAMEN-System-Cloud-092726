<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('petty_cash_liquidations')) {
            Schema::create('petty_cash_liquidations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->date('liquidation_date')->index();
                $table->date('period_from')->nullable();
                $table->date('period_to');
                $table->unsignedBigInteger('through_journal_entry_id')->nullable();
                $table->decimal('book_balance', 14, 2);
                $table->decimal('actual_cash_count', 14, 2);
                $table->decimal('variance_amount', 14, 2)->default(0);
                $table->decimal('covered_outflow', 14, 2)->default(0);
                $table->string('reconciliation_status', 20)->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->index(['business_id', 'branch_id', 'liquidation_date'], 'petty_liq_business_branch_date_idx');
            });
        }

        if (Schema::hasTable('payroll_runs') && ! Schema::hasColumn('payroll_runs', 'payment_account_id')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->foreignId('payment_account_id')
                    ->nullable()
                    ->after('paid_at')
                    ->constrained('chart_of_accounts')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('payroll_runs') && ! Schema::hasColumn('payroll_runs', 'payment_reference')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->string('payment_reference', 160)->nullable()->after('payment_account_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_runs') && Schema::hasColumn('payroll_runs', 'payment_reference')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropColumn('payment_reference');
            });
        }

        if (Schema::hasTable('payroll_runs') && Schema::hasColumn('payroll_runs', 'payment_account_id')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                $table->dropConstrainedForeignId('payment_account_id');
            });
        }

        Schema::dropIfExists('petty_cash_liquidations');
    }
};
