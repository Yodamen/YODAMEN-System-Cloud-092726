<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_recoveries')) {
            Schema::create('payroll_recoveries', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80)->unique();
                $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('recovery_type', 30)->index(); // COMMISSION or INCENTIVE
                $table->foreignId('source_commission_earning_id')->nullable()->constrained('commission_earnings')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('source_adjustment_earning_id')->nullable()->unique('payroll_recovery_source_adjustment_uq')->constrained('commission_earnings')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('source_transaction_action_request_id')->nullable()->constrained('transaction_action_requests')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('original_payroll_run_id')->nullable()->constrained('payroll_runs')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('original_payroll_line_id')->nullable()->constrained('payroll_lines')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('origin_work_date')->nullable()->index();
                $table->string('source_label', 190)->nullable();
                $table->decimal('recovery_amount', 14, 2);
                $table->decimal('applied_amount', 14, 2)->default(0);
                $table->decimal('remaining_amount', 14, 2);
                $table->string('status', 20)->default('OPEN')->index();
                $table->text('reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps(6);

                $table->index(['business_id', 'status', 'created_at'], 'payroll_recovery_business_status_idx');
                $table->index(['personnel_id', 'status', 'created_at'], 'payroll_recovery_personnel_status_idx');
            });
        }

        if (! Schema::hasTable('payroll_recovery_allocations')) {
            Schema::create('payroll_recovery_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_recovery_id')->constrained('payroll_recoveries')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('payroll_line_id')->constrained('payroll_lines')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->string('status', 20)->default('RESERVED')->index(); // RESERVED or APPLIED
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('applied_at')->nullable();
                $table->timestamps(6);

                $table->unique(['payroll_recovery_id', 'payroll_run_id'], 'payroll_recovery_run_uq');
                $table->index(['payroll_run_id', 'status'], 'payroll_recovery_alloc_run_status_idx');
                $table->index(['payroll_line_id', 'status'], 'payroll_recovery_alloc_line_status_idx');
            });
        }

        Schema::table('payroll_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_lines', 'payroll_recovery_deductions')) {
                $table->decimal('payroll_recovery_deductions', 12, 2)->default(0)->after('cash_advance_deductions');
            }
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_runs', 'payroll_recovery_deductions')) {
                $table->decimal('payroll_recovery_deductions', 14, 2)->default(0)->after('cash_advance_deductions');
            }
            if (! Schema::hasColumn('payroll_runs', 'calculated_at')) {
                $table->timestamp('calculated_at', 6)->nullable()->after('notes');
            }
        });

        // Existing Drafts predate the recovery-ledger feature. Give them a calculation
        // watermark so any recovery created after activation correctly marks them stale.
        if (Schema::hasColumn('payroll_runs', 'calculated_at')) {
            DB::table('payroll_runs')
                ->where('status', 'DRAFT')
                ->whereNull('calculated_at')
                ->update(['calculated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_recovery_allocations');
        Schema::dropIfExists('payroll_recoveries');

        Schema::table('payroll_lines', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_lines', 'payroll_recovery_deductions')) {
                $table->dropColumn('payroll_recovery_deductions');
            }
        });
        Schema::table('payroll_runs', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('payroll_runs', 'payroll_recovery_deductions')) $columns[] = 'payroll_recovery_deductions';
            if (Schema::hasColumn('payroll_runs', 'calculated_at')) $columns[] = 'calculated_at';
            if ($columns) $table->dropColumn($columns);
        });
    }
};
