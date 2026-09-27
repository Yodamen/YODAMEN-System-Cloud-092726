<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guard each step so the migration can safely resume after partial MySQL DDL.
        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->date('period_start');
                $table->date('period_end');
                $table->date('pay_date');
                $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('status', 30)->default('DRAFT')->index();
                $table->decimal('gross_compensation', 14, 2)->default(0);
                $table->decimal('adjustments_total', 14, 2)->default(0);
                $table->decimal('cash_advance_deductions', 14, 2)->default(0);
                $table->decimal('net_pay', 14, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('approved_at')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('paid_at')->nullable();
                $table->timestamps();
                $table->index(
                    ['business_id', 'period_start', 'period_end', 'status'],
                    'payrun_business_period_status_idx'
                );
            });
        }

        if (! Schema::hasTable('payroll_lines')) {
            Schema::create('payroll_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('personnel_code_snapshot');
                $table->string('personnel_name_snapshot');
                $table->string('position_snapshot')->nullable();
                $table->decimal('hourly_basic_pay', 12, 2)->default(0);
                $table->decimal('ot_pay', 12, 2)->default(0);
                $table->decimal('commission_amount', 12, 2)->default(0);
                $table->decimal('guaranteed_top_up', 12, 2)->default(0);
                $table->decimal('gross_compensation', 12, 2)->default(0);
                $table->decimal('adjustments_total', 12, 2)->default(0);
                $table->decimal('cash_advance_deductions', 12, 2)->default(0);
                $table->decimal('net_pay', 12, 2)->default(0);
                $table->string('status', 30)->default('DRAFT');
                $table->timestamps();
                $table->unique(
                    ['payroll_run_id', 'personnel_id'],
                    'payline_run_person_unique'
                );
                $table->index(
                    ['business_id', 'personnel_id', 'status'],
                    'payline_business_person_status_idx'
                );
            });
        }

        if (! Schema::hasTable('payroll_cash_advance_allocations')) {
            Schema::create('payroll_cash_advance_allocations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('payroll_run_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('payroll_line_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('cash_advance_id')->constrained('employee_cash_advances')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('amount', 12, 2);
                $table->string('status', 30)->default('RESERVED');
                $table->foreignId('ledger_entry_id')->nullable()->constrained('employee_cash_advance_ledger')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->unique(
                    ['payroll_line_id', 'cash_advance_id'],
                    'pca_line_advance_unique'
                );
            });
        }

        if (! Schema::hasColumn('daily_compensations', 'payroll_run_id')) {
            Schema::table('daily_compensations', function (Blueprint $table) {
                $table->foreignId('payroll_run_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('payroll_runs')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('daily_compensations', 'payroll_line_id')) {
            Schema::table('daily_compensations', function (Blueprint $table) {
                $table->foreignId('payroll_line_id')
                    ->nullable()
                    ->after('payroll_run_id')
                    ->constrained('payroll_lines')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('commission_earnings', 'payroll_run_id')) {
            Schema::table('commission_earnings', function (Blueprint $table) {
                $table->foreignId('payroll_run_id')
                    ->nullable()
                    ->after('status')
                    ->constrained('payroll_runs')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('commission_earnings', 'payroll_line_id')) {
            Schema::table('commission_earnings', function (Blueprint $table) {
                $table->foreignId('payroll_line_id')
                    ->nullable()
                    ->after('payroll_run_id')
                    ->constrained('payroll_lines')
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('commission_earnings')) {
            if (Schema::hasColumn('commission_earnings', 'payroll_line_id')) {
                Schema::table('commission_earnings', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('payroll_line_id');
                });
            }
            if (Schema::hasColumn('commission_earnings', 'payroll_run_id')) {
                Schema::table('commission_earnings', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('payroll_run_id');
                });
            }
        }

        if (Schema::hasTable('daily_compensations')) {
            if (Schema::hasColumn('daily_compensations', 'payroll_line_id')) {
                Schema::table('daily_compensations', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('payroll_line_id');
                });
            }
            if (Schema::hasColumn('daily_compensations', 'payroll_run_id')) {
                Schema::table('daily_compensations', function (Blueprint $table) {
                    $table->dropConstrainedForeignId('payroll_run_id');
                });
            }
        }

        Schema::dropIfExists('payroll_cash_advance_allocations');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_runs');
    }
};
