<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accounting_product_mappings')) {
            Schema::create('accounting_product_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('inventory_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('service_cost_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_cost_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'retail_product_id'], 'acct_product_mapping_uq');
            });
        }

        if (! Schema::hasTable('accounting_payment_method_mappings')) {
            Schema::create('accounting_payment_method_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('payment_method_reference_id')->constrained('reference_values')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('cash_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'payment_method_reference_id'], 'acct_payment_mapping_uq');
            });
        }

        if (! Schema::hasTable('bank_reconciliations')) {
            Schema::create('bank_reconciliations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
                $table->string('code', 70)->unique();
                $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('statement_date')->index();
                $table->decimal('statement_ending_balance', 14, 2);
                $table->decimal('book_balance_snapshot', 14, 2)->default(0);
                $table->decimal('adjusted_bank_balance', 14, 2)->default(0);
                $table->decimal('adjusted_book_balance', 14, 2)->default(0);
                $table->decimal('difference', 14, 2)->default(0);
                $table->string('status', 30)->default('DRAFT')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('reconciled_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('reconciled_at')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'statement_date'], 'bank_recon_business_date_idx');
            });
        }

        if (! Schema::hasTable('bank_reconciliation_items')) {
            Schema::create('bank_reconciliation_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('bank_reconciliation_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
                $table->unsignedSmallInteger('line_no');
                $table->string('item_type', 50);
                $table->string('effect_on', 10); // BANK or BOOK
                $table->string('description', 255);
                $table->string('reference', 120)->nullable();
                $table->decimal('amount', 14, 2); // signed: + increases selected side, - decreases
                $table->foreignId('adjustment_account_id')->nullable()->constrained('chart_of_accounts')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('journal_entry_id')->nullable()->constrained('accounting_journal_entries')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->unique(['bank_reconciliation_id', 'line_no'], 'bank_recon_line_no_uq');
            });
        }

        if (! Schema::hasTable('profit_sharing_runs')) {
            Schema::create('profit_sharing_runs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
                $table->string('code', 70)->unique();
                $table->date('period_start');
                $table->date('period_end');
                $table->string('basis', 20)->default('ACCRUAL');
                $table->string('cost_basis', 30)->default('AUDIT_ADJUSTED');
                $table->decimal('net_income_snapshot', 14, 2)->default(0);
                $table->decimal('distributable_profit', 14, 2)->default(0);
                $table->decimal('total_percentage', 9, 4)->default(0);
                $table->decimal('total_profit_share', 14, 2)->default(0);
                $table->string('status', 20)->default('DRAFT')->index();
                $table->text('notes')->nullable();
                $table->foreignId('payment_account_id')->nullable()->constrained('chart_of_accounts')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('approved_at')->nullable();
                $table->foreignId('paid_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('paid_at')->nullable();
                $table->timestamps();
                $table->index(['business_id', 'period_start', 'period_end'], 'profit_share_period_idx');
            });
        }

        if (! Schema::hasTable('profit_sharing_lines')) {
            Schema::create('profit_sharing_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('profit_sharing_run_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('personnel_code_snapshot', 60)->nullable();
                $table->string('personnel_name_snapshot', 180);
                $table->decimal('percentage_snapshot', 9, 4);
                $table->decimal('distributable_profit_snapshot', 14, 2);
                $table->decimal('share_amount', 14, 2);
                $table->string('status', 20)->default('DRAFT');
                $table->timestamps();
                $table->unique(['profit_sharing_run_id', 'personnel_id'], 'profit_share_personnel_uq');
            });
        }

        $newAccounts = [
            ['1103','Accounts Receivable – Customers','ASSET','Current Asset','DEBIT','OPERATING',false,false,'AR_CUSTOMERS'],
            ['2007','Customer Advances / Unapplied Receipts','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'CUSTOMER_ADVANCES'],
            ['2008','Deferred Package Revenue','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'DEFERRED_PACKAGE_REVENUE'],
            ['2009','Deferred Membership Revenue','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'DEFERRED_MEMBERSHIP_REVENUE'],
            ['2011','Profit Sharing Payable','LIABILITY','Current Liability','CREDIT','FINANCING',false,false,'PROFIT_SHARING_PAYABLE'],
            ['3003','Partner Profit Distributions','EQUITY','Drawings','DEBIT','FINANCING',false,false,'PARTNER_PROFIT_DISTRIBUTIONS'],
            ['4002','Sales Discounts & Allowances','INCOME','Contra Revenue','DEBIT','OPERATING',false,false,'SALES_DISCOUNTS'],
            ['4003','Sales Returns & Refunds','INCOME','Contra Revenue','DEBIT','OPERATING',false,false,'SALES_RETURNS'],
            ['5103','Other Service Commissions','COST_OF_SALES','Direct Labor','DEBIT','OPERATING',false,false,'COS_OTHER_SERVICE_COMMISSIONS'],
            ['6206','Retail / Sales Commissions','OPERATING_EXPENSE','Marketing','DEBIT','OPERATING',true,false,'RETAIL_SALES_COMMISSIONS'],
        ];

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            foreach ($newAccounts as $row) {
                DB::table('chart_of_accounts')->updateOrInsert(
                    ['business_id' => $businessId, 'code' => $row[0]],
                    [
                        'name' => $row[1], 'account_type' => $row[2], 'account_group' => $row[3],
                        'normal_balance' => $row[4], 'cash_flow_classification' => $row[5],
                        'is_opex' => $row[6], 'is_cash_account' => $row[7], 'system_key' => $row[8],
                        'posting_type' => 'POSTING', 'active' => true, 'updated_at' => now(), 'created_at' => now(),
                    ]
                );
            }

            foreach ([
                '6001' => 'SALARY_BARBERS',
                '6002' => 'SALARY_THERAPISTS',
                '6003' => 'SALARY_ADMIN',
            ] as $code => $key) {
                DB::table('chart_of_accounts')->where('business_id', $businessId)->where('code', $code)->update(['system_key' => $key, 'updated_at' => now()]);
            }

            $accountIds = DB::table('chart_of_accounts')->where('business_id', $businessId)->pluck('id', 'system_key');
            $products = DB::table('retail_products')->where('business_id', $businessId)->get(['id', 'retail_available', 'category']);
            foreach ($products as $product) {
                $category = strtoupper((string) ($product->category ?? ''));
                if ($product->retail_available) {
                    $inventoryKey = 'INVENTORY_RETAIL_PRODUCTS';
                    $serviceCostKey = 'COS_SERVICE_CONSUMABLES';
                } elseif (str_contains($category, 'BARBER') || str_contains($category, 'CONSUMABLE')) {
                    $inventoryKey = 'INVENTORY_BARBER_CONSUMABLES';
                    $serviceCostKey = 'COS_SERVICE_CONSUMABLES';
                } else {
                    $inventoryKey = 'INVENTORY_TREATMENT_CHEMICALS';
                    $serviceCostKey = 'COS_SERVICE_CHEMICALS';
                }
                if (! isset($accountIds[$inventoryKey], $accountIds[$serviceCostKey], $accountIds['COS_RETAIL_PRODUCTS'])) continue;
                DB::table('accounting_product_mappings')->updateOrInsert(
                    ['business_id' => $businessId, 'retail_product_id' => $product->id],
                    [
                        'inventory_account_id' => $accountIds[$inventoryKey],
                        'service_cost_account_id' => $accountIds[$serviceCostKey],
                        'retail_cost_account_id' => $accountIds['COS_RETAIL_PRODUCTS'],
                        'created_at' => now(), 'updated_at' => now(),
                    ]
                );
            }

            $paymentMethods = DB::table('reference_values as rv')
                ->join('reference_groups as rg', 'rg.id', '=', 'rv.reference_group_id')
                ->where('rg.business_id', $businessId)
                ->where('rg.code', 'PAYMENT_METHOD')
                ->get(['rv.id', 'rv.code', 'rv.display_value']);
            foreach ($paymentMethods as $method) {
                $text = strtoupper(trim(($method->code ?? '').' '.($method->display_value ?? '')));
                $cashKey = str_contains($text, 'CASH')
                    ? 'CASH_ON_HAND'
                    : ((str_contains($text, 'BANK') || str_contains($text, 'TRANSFER')) ? 'CASH_IN_BANK' : 'POS_CLEARING');
                if (! isset($accountIds[$cashKey])) continue;
                DB::table('accounting_payment_method_mappings')->updateOrInsert(
                    ['business_id' => $businessId, 'payment_method_reference_id' => $method->id],
                    ['cash_account_id' => $accountIds[$cashKey], 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        $permissions = [
            ['ar','VIEW'], ['collections','VIEW'], ['cash-flow','VIEW'], ['my-profit','VIEW'],
            ['bank-reconciliation','VIEW'], ['bank-reconciliation','CREATE'], ['bank-reconciliation','EDIT'], ['bank-reconciliation','RECONCILE'],
            ['profit-sharing','VIEW'], ['profit-sharing','CREATE'], ['profit-sharing','EDIT'], ['profit-sharing','APPROVE'], ['profit-sharing','MARK_PAID'],
            ['accounting-mapping','VIEW'], ['accounting-mapping','EDIT'],
        ];
        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $module.'.'.$action],
                ['module' => $module, 'action' => $action, 'name' => str($module)->replace('-', ' ')->title().' - '.str($action)->replace('_', ' ')->title(), 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $permissionIds = DB::table('permissions')->whereIn('code', collect($permissions)->map(fn ($r) => $r[0].'.'.$r[1]))->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }

        // My Profit is a self-service view. Any authenticated role may access it;
        // users without a linked profit-sharing personnel record simply see no rows.
        $myProfitPermissionId = DB::table('permissions')->where('code', 'my-profit.VIEW')->value('id');
        if ($myProfitPermissionId) {
            foreach (DB::table('roles')->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $myProfitPermissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('profit_sharing_lines');
        Schema::dropIfExists('profit_sharing_runs');
        Schema::dropIfExists('bank_reconciliation_items');
        Schema::dropIfExists('bank_reconciliations');
        Schema::dropIfExists('accounting_payment_method_mappings');
        Schema::dropIfExists('accounting_product_mappings');
    }
};
