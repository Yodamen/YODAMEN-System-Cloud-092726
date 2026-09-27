<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chart_of_accounts')) {
            Schema::create('chart_of_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 40);
                $table->string('name', 180);
                $table->string('account_type', 40)->index();
                $table->string('account_group', 100)->nullable()->index();
                $table->string('normal_balance', 10);
                $table->string('posting_type', 20)->default('POSTING');
                $table->string('cash_flow_classification', 30)->nullable();
                $table->boolean('is_opex')->default(false);
                $table->boolean('is_cash_account')->default(false);
                $table->string('system_key', 100)->nullable();
                $table->boolean('active')->default(true)->index();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'code'], 'coa_business_code_uq');
                $table->unique(['business_id', 'system_key'], 'coa_business_system_key_uq');
            });
        }

        if (Schema::hasTable('accounting_journal_entries') && ! Schema::hasColumn('accounting_journal_entries', 'branch_id')) {
            Schema::table('accounting_journal_entries', function (Blueprint $table) {
                $table->foreignId('branch_id')->nullable()->after('business_id')->constrained()->cascadeOnUpdate()->nullOnDelete();
            });
        }

        if (Schema::hasTable('accounting_journal_lines') && ! Schema::hasColumn('accounting_journal_lines', 'account_id')) {
            Schema::table('accounting_journal_lines', function (Blueprint $table) {
                $table->foreignId('account_id')->nullable()->after('line_no')->constrained('chart_of_accounts')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        if (! Schema::hasTable('expenses')) {
            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->date('expense_date')->index();
                $table->string('vendor_payee', 180);
                $table->string('reference', 120)->nullable();
                $table->string('description', 255)->nullable();
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('status', 30)->default('DRAFT')->index();
                $table->string('payment_status', 30)->default('UNPAID')->index();
                $table->foreignId('payment_account_id')->nullable()->constrained('chart_of_accounts')->cascadeOnUpdate()->nullOnDelete();
                $table->string('payment_method', 60)->nullable();
                $table->string('payment_reference', 120)->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->string('attachment_disk', 40)->nullable();
                $table->string('attachment_path', 500)->nullable();
                $table->string('attachment_name', 255)->nullable();
                $table->string('attachment_mime', 120)->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('posted_at')->nullable();
                $table->foreignId('posted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->text('reversal_reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->index(['business_id', 'expense_date'], 'expense_business_date_idx');
                $table->index(['business_id', 'status', 'payment_status'], 'expense_business_status_idx');
            });
        }

        if (! Schema::hasTable('expense_lines')) {
            Schema::create('expense_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('expense_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
                $table->unsignedSmallInteger('line_no');
                $table->foreignId('account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('description', 255)->nullable();
                $table->decimal('amount', 14, 2);
                $table->timestamps();

                $table->unique(['expense_id', 'line_no'], 'expense_line_no_uq');
                $table->index(['account_id', 'expense_id'], 'expense_line_account_idx');
            });
        }

        $permissions = [
            ['expenses', 'VIEW'],
            ['expenses', 'CREATE'],
            ['expenses', 'EDIT'],
            ['expenses', 'POST'],
            ['expenses', 'MARK_PAID'],
            ['expenses', 'REVERSE'],
            ['coa', 'VIEW'],
            ['coa', 'CREATE'],
            ['coa', 'EDIT'],
            ['coa', 'TOGGLE'],
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
            ->whereIn('code', collect($permissions)->map(fn ($row) => $row[0].'.'.$row[1])->all())
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
        $codes = [
            'expenses.CREATE', 'expenses.EDIT', 'expenses.POST', 'expenses.MARK_PAID', 'expenses.REVERSE',
            'coa.CREATE', 'coa.EDIT', 'coa.TOGGLE',
        ];
        $ids = DB::table('permissions')->whereIn('code', $codes)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expenses');

        if (Schema::hasTable('accounting_journal_lines') && Schema::hasColumn('accounting_journal_lines', 'account_id')) {
            Schema::table('accounting_journal_lines', function (Blueprint $table) {
                $table->dropConstrainedForeignId('account_id');
            });
        }
        if (Schema::hasTable('accounting_journal_entries') && Schema::hasColumn('accounting_journal_entries', 'branch_id')) {
            Schema::table('accounting_journal_entries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('branch_id');
            });
        }

        Schema::dropIfExists('chart_of_accounts');
    }
};
