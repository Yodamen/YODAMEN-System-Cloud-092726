<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cash_deposits')) {
            Schema::create('cash_deposits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->date('business_date')->index();
                $table->date('deposit_date')->index();
                $table->foreignId('source_cash_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('expected_cash_sales_snapshot', 16, 2)->default(0);
                $table->decimal('amount', 16, 2);
                $table->string('reference', 160)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('POSTED')->index();
                $table->foreignId('journal_entry_id')->nullable()->constrained('accounting_journal_entries')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('accounting_journal_entries')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'branch_id', 'business_date'], 'cash_deposit_business_branch_day_idx');
                $table->index(['business_id', 'status', 'deposit_date'], 'cash_deposit_business_status_date_idx');
            });
        }

        if (! Schema::hasTable('business_funding_transactions')) {
            Schema::create('business_funding_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
                $table->string('code', 70)->unique();
                $table->date('funding_date')->index();
                $table->string('funding_type', 30)->index(); // CAPITAL_CONTRIBUTION or OWNER_LOAN
                $table->foreignId('cash_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('amount', 16, 2);
                $table->string('reference', 160)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('POSTED')->index();
                $table->foreignId('journal_entry_id')->nullable()->constrained('accounting_journal_entries')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('accounting_journal_entries')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('reversed_at')->nullable();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'funding_date', 'status'], 'business_funding_business_date_status_idx');
            });
        }

        Schema::table('personnel', function (Blueprint $table) {
            if (! Schema::hasColumn('personnel', 'sss_enabled')) {
                $table->boolean('sss_enabled')->default(false)->after('paid_leave_entitled');
                $table->string('sss_number', 40)->nullable()->after('sss_enabled');
                $table->boolean('philhealth_enabled')->default(false)->after('sss_number');
                $table->string('philhealth_number', 40)->nullable()->after('philhealth_enabled');
                $table->boolean('pagibig_enabled')->default(false)->after('philhealth_number');
                $table->string('pagibig_number', 40)->nullable()->after('pagibig_enabled');
            }
        });

        Schema::table('payroll_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_lines', 'sss_employee')) {
                $table->json('statutory_programs_snapshot')->nullable()->after('cash_advance_deductions');
                $table->decimal('sss_employee', 12, 2)->default(0)->after('statutory_programs_snapshot');
                $table->decimal('sss_employer', 12, 2)->default(0)->after('sss_employee');
                $table->decimal('philhealth_employee', 12, 2)->default(0)->after('sss_employer');
                $table->decimal('philhealth_employer', 12, 2)->default(0)->after('philhealth_employee');
                $table->decimal('pagibig_employee', 12, 2)->default(0)->after('philhealth_employer');
                $table->decimal('pagibig_employer', 12, 2)->default(0)->after('pagibig_employee');
                $table->decimal('statutory_employee_deductions', 12, 2)->default(0)->after('pagibig_employer');
                $table->decimal('statutory_employer_contributions', 12, 2)->default(0)->after('statutory_employee_deductions');
            }
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('payroll_runs', 'statutory_employee_deductions')) {
                $table->decimal('statutory_employee_deductions', 14, 2)->default(0)->after('cash_advance_deductions');
                $table->decimal('statutory_employer_contributions', 14, 2)->default(0)->after('statutory_employee_deductions');
            }
        });

        $accounts = [
            ['2012', 'SSS Contributions Payable', 'LIABILITY', 'Current Liability', 'CREDIT', 'OPERATING', false, 'SSS_PAYABLE'],
            ['2013', 'PhilHealth Contributions Payable', 'LIABILITY', 'Current Liability', 'CREDIT', 'OPERATING', false, 'PHILHEALTH_PAYABLE'],
            ['2014', 'Pag-IBIG Contributions Payable', 'LIABILITY', 'Current Liability', 'CREDIT', 'OPERATING', false, 'PAGIBIG_PAYABLE'],
            ['6007', 'Employer Statutory Contributions', 'OPERATING_EXPENSE', 'Payroll', 'DEBIT', 'OPERATING', true, 'EMPLOYER_STATUTORY_CONTRIBUTIONS'],
        ];

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            foreach ($accounts as $account) {
                DB::table('chart_of_accounts')->updateOrInsert(
                    ['business_id' => $businessId, 'code' => $account[0]],
                    [
                        'name' => $account[1],
                        'account_type' => $account[2],
                        'account_group' => $account[3],
                        'normal_balance' => $account[4],
                        'posting_type' => 'POSTING',
                        'cash_flow_classification' => $account[5],
                        'is_opex' => $account[6],
                        'is_cash_account' => false,
                        'is_funding_source' => false,
                        'system_key' => $account[7],
                        'active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            // Cash on Hand receives customer cash, but should not be offered as an
            // operating disbursement source. Cash is moved to a bank through Cash Deposits.
            DB::table('chart_of_accounts')
                ->where('business_id', $businessId)
                ->where('system_key', 'CASH_ON_HAND')
                ->update(['is_funding_source' => false, 'updated_at' => now()]);
        }

        $permissions = [
            ['cash-deposits', 'VIEW'], ['cash-deposits', 'CREATE'], ['cash-deposits', 'REVERSE'],
            ['cash-management', 'CREATE'], ['cash-management', 'REVERSE'],
        ];
        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $module.'.'.$action],
                [
                    'module' => $module,
                    'action' => $action,
                    'name' => str($module)->replace('-', ' ')->title().' - '.str($action)->replace('_', ' ')->title(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', collect($permissions)->map(fn ($item) => $item[0].'.'.$item[1]))
            ->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_funding_transactions');
        Schema::dropIfExists('cash_deposits');

        Schema::table('payroll_runs', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_runs', 'statutory_employee_deductions')) {
                $table->dropColumn(['statutory_employee_deductions', 'statutory_employer_contributions']);
            }
        });
        Schema::table('payroll_lines', function (Blueprint $table) {
            if (Schema::hasColumn('payroll_lines', 'sss_employee')) {
                $table->dropColumn([
                    'statutory_programs_snapshot', 'sss_employee', 'sss_employer', 'philhealth_employee', 'philhealth_employer',
                    'pagibig_employee', 'pagibig_employer', 'statutory_employee_deductions', 'statutory_employer_contributions',
                ]);
            }
        });
        Schema::table('personnel', function (Blueprint $table) {
            if (Schema::hasColumn('personnel', 'sss_enabled')) {
                $table->dropColumn([
                    'sss_enabled', 'sss_number', 'philhealth_enabled', 'philhealth_number', 'pagibig_enabled', 'pagibig_number',
                ]);
            }
        });
    }
};
