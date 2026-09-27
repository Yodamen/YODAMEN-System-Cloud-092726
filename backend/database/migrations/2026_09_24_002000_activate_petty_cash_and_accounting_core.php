<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('petty_cash_transactions')) {
            Schema::create('petty_cash_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->date('transaction_date')->index();
                $table->string('transaction_type', 30)->index();
                $table->string('direction', 10);
                $table->foreignId('counterpart_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->string('reference', 120)->nullable();
                $table->string('payee', 180)->nullable();
                $table->text('description')->nullable();
                $table->string('status', 30)->default('POSTED')->index();
                $table->foreignId('journal_entry_id')->nullable()->constrained('accounting_journal_entries')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('reversed_at')->nullable();
                $table->foreignId('reversed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->text('reversal_reason')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'branch_id', 'transaction_date'], 'petty_cash_business_branch_date_idx');
            });
        }

        if (! Schema::hasTable('accounting_daily_closings')) {
            Schema::create('accounting_daily_closings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->date('closing_date');
                $table->string('status', 20)->default('CLOSED')->index();
                $table->text('notes')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->foreignId('closed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('reopened_at')->nullable();
                $table->foreignId('reopened_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->text('reopen_reason')->nullable();
                $table->timestamps();
                $table->unique(['business_id', 'branch_id', 'closing_date'], 'acct_daily_close_uq');
            });
        }

        $permissions = [
            ['petty-cash','VIEW'], ['petty-cash','CREATE'], ['petty-cash','REVERSE'],
            ['general-ledger','VIEW'], ['trial-balance','VIEW'], ['cash-management','VIEW'],
            ['daily-closing','VIEW'], ['daily-closing','CLOSE'], ['daily-closing','REOPEN'],
            ['financial-statements','VIEW'],
        ];
        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $module.'.'.$action],
                ['module'=>$module,'action'=>$action,'name'=>str($module)->replace('-',' ')->title().' - '.str($action)->replace('_',' ')->title(),'created_at'=>now(),'updated_at'=>now()]
            );
        }
        $permissionIds = DB::table('permissions')->whereIn('code', collect($permissions)->map(fn($r)=>$r[0].'.'.$r[1]))->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA','ROLE-MGR'])->pluck('id');
        foreach ($roleIds as $roleId) foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId], ['created_at'=>now(),'updated_at'=>now()]);
        }

        $businessIds = DB::table('businesses')->pluck('id');
        foreach ($businessIds as $businessId) {
            $rows = [
                ['code'=>'4901','name'=>'Inventory Count Gain','account_type'=>'INCOME','account_group'=>'Inventory Adjustments','normal_balance'=>'CREDIT','posting_type'=>'POSTING','cash_flow_classification'=>'NON_CASH','is_opex'=>false,'is_cash_account'=>false,'system_key'=>'INVENTORY_COUNT_GAIN'],
                ['code'=>'5004','name'=>'Inventory Shrinkage / Count Variance','account_type'=>'COST_OF_SALES','account_group'=>'Inventory Adjustments','normal_balance'=>'DEBIT','posting_type'=>'POSTING','cash_flow_classification'=>'NON_CASH','is_opex'=>false,'is_cash_account'=>false,'system_key'=>'INVENTORY_COUNT_VARIANCE'],
            ];
            foreach ($rows as $row) {
                DB::table('chart_of_accounts')->updateOrInsert(
                    ['business_id'=>$businessId,'code'=>$row['code']],
                    [...$row,'active'=>true,'notes'=>'System account used by approved inventory audit/count adjustments.','created_at'=>now(),'updated_at'=>now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_daily_closings');
        Schema::dropIfExists('petty_cash_transactions');
    }
};
