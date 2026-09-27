<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This migration may have partially run on MySQL before Laravel could
        // record it in the migrations table. Guard each table so rerunning is safe.
        if (! Schema::hasTable('employee_cash_advances')) {
            Schema::create('employee_cash_advances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('issued_date');
                $table->decimal('original_amount', 12, 2);
                $table->decimal('deduction_per_payroll', 12, 2)->default(0);
                $table->string('purpose')->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 30)->default('DRAFT')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('approved_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('closed_at')->nullable();
                $table->timestamps();
                $table->index(
                    ['business_id', 'personnel_id', 'status'],
                    'eca_business_person_status_idx'
                );
            });
        }

        if (! Schema::hasTable('employee_cash_advance_ledger')) {
            Schema::create('employee_cash_advance_ledger', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('cash_advance_id')->constrained('employee_cash_advances')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('entry_date');
                $table->string('entry_type', 40);
                $table->decimal('amount', 12, 2); // ISSUE positive; repayments/deductions negative.
                $table->foreignId('payroll_run_id')->nullable();
                $table->foreignId('payroll_line_id')->nullable();
                $table->foreignId('reversal_of_id')->nullable()->constrained('employee_cash_advance_ledger')->cascadeOnUpdate()->nullOnDelete();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->index(
                    ['business_id', 'cash_advance_id', 'entry_date'],
                    'eca_ledger_business_advance_date_idx'
                );
            });
        }

        if (! Schema::hasTable('payroll_adjustments')) {
            Schema::create('payroll_adjustments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('effective_date');
                $table->string('adjustment_type', 40)->default('OTHER');
                $table->decimal('amount', 12, 2); // positive earning, negative deduction
                $table->string('description');
                $table->text('reason');
                $table->string('status', 30)->default('OPEN')->index();
                $table->foreignId('payroll_run_id')->nullable();
                $table->foreignId('payroll_line_id')->nullable();
                $table->foreignId('reversal_of_id')->nullable()->constrained('payroll_adjustments')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->index(
                    ['business_id', 'personnel_id', 'effective_date', 'status'],
                    'pay_adj_business_person_date_status_idx'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_adjustments');
        Schema::dropIfExists('employee_cash_advance_ledger');
        Schema::dropIfExists('employee_cash_advances');
    }
};
