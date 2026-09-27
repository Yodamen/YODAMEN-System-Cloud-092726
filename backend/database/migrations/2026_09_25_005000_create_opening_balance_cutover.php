<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_opening_balances')) {
            Schema::create('accounting_opening_balances', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->date('cutover_date')->index();
                $table->json('cash_snapshot')->nullable();
                $table->json('inventory_snapshot')->nullable();
                $table->decimal('cash_total', 16, 2)->default(0);
                $table->decimal('inventory_total', 16, 2)->default(0);
                $table->foreignId('offset_account_id')->nullable()->constrained('chart_of_accounts')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('journal_entry_id')->nullable()->constrained('accounting_journal_entries')->cascadeOnUpdate()->nullOnDelete();
                $table->string('status', 20)->default('POSTED')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'branch_id'], 'opening_balance_business_branch_uq');
            });
        }

        if (Schema::hasTable('chart_of_accounts')) {
            $businessIds = DB::table('businesses')->pluck('id');
            foreach ($businessIds as $businessId) {
                $exists = DB::table('chart_of_accounts')
                    ->where('business_id', $businessId)
                    ->where(function ($query) {
                        $query->where('system_key', 'OPENING_BALANCE_EQUITY')->orWhere('code', '3999');
                    })
                    ->exists();

                if (! $exists) {
                    DB::table('chart_of_accounts')->insert([
                        'business_id' => $businessId,
                        'code' => '3999',
                        'name' => 'Opening Balance Equity',
                        'account_type' => 'EQUITY',
                        'account_group' => 'Opening Balance',
                        'financial_statement_group' => Schema::hasColumn('chart_of_accounts', 'financial_statement_group') ? 'Opening Balance Equity' : null,
                        'normal_balance' => 'CREDIT',
                        'posting_type' => 'POSTING',
                        'cash_flow_classification' => 'NON_CASH',
                        'is_opex' => false,
                        'is_cash_account' => false,
                        'is_funding_source' => Schema::hasColumn('chart_of_accounts', 'is_funding_source') ? false : null,
                        'system_key' => 'OPENING_BALANCE_EQUITY',
                        'active' => true,
                        'notes' => 'Temporary cutover equity used to establish opening balances without affecting current-period profit or cash-flow activity.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_opening_balances');
    }
};
