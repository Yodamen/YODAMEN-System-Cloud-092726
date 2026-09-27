<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            DB::table('chart_of_accounts')->updateOrInsert(
                ['business_id' => $businessId, 'code' => '1005'],
                [
                    'name' => 'Cash with Manager / Undeposited Cash',
                    'account_type' => 'ASSET',
                    'account_group' => 'Current Asset',
                    'normal_balance' => 'DEBIT',
                    'posting_type' => 'POSTING',
                    'cash_flow_classification' => 'OPERATING',
                    'is_opex' => false,
                    'is_cash_account' => true,
                    'is_funding_source' => false,
                    'system_key' => 'CASH_WITH_MANAGER',
                    'active' => true,
                    'notes' => 'System cash-custody account used when physical cash sales are remitted to a manager before bank deposit.',
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        if (Schema::hasTable('permissions')) {
            foreach (DB::table('permissions')->where('module', 'cash-deposits')->get(['id','action']) as $permission) {
                DB::table('permissions')->where('id', $permission->id)->update([
                    'name' => 'Cash Handling - '.str($permission->action)->replace('_', ' ')->title()->toString(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (! Schema::hasTable('cash_remittances')) {
            Schema::create('cash_remittances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 70)->unique();
                $table->date('business_date')->index();
                $table->date('remittance_date')->index();
                $table->foreignId('source_cash_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('custody_cash_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('received_by_user_id')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->string('received_by_name_snapshot', 180)->nullable();
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
                $table->index(['business_id', 'branch_id', 'business_date'], 'cash_remit_business_branch_day_idx');
                $table->index(['business_id', 'status', 'remittance_date'], 'cash_remit_business_status_date_idx');
            });
        }

        Schema::table('cash_deposits', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_deposits', 'deposit_source')) {
                $table->string('deposit_source', 20)->default('DIRECT')->after('deposit_date')->index();
            }
            if (! Schema::hasColumn('cash_deposits', 'cash_remittance_id')) {
                $table->foreignId('cash_remittance_id')->nullable()->after('deposit_source')
                    ->constrained('cash_remittances')->cascadeOnUpdate()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('cash_deposits')) {
            Schema::table('cash_deposits', function (Blueprint $table) {
                if (Schema::hasColumn('cash_deposits', 'cash_remittance_id')) {
                    $table->dropConstrainedForeignId('cash_remittance_id');
                }
                if (Schema::hasColumn('cash_deposits', 'deposit_source')) {
                    $table->dropColumn('deposit_source');
                }
            });
        }

        Schema::dropIfExists('cash_remittances');

        // Keep the system cash-custody account on rollback because historical cash-deposit rows may still reference it.
    }
};
