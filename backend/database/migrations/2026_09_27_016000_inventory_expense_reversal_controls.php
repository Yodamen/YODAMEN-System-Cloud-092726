<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_adjustment_requests')) {
            Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
                if (! Schema::hasColumn('inventory_adjustment_requests', 'request_type')) {
                    $table->string('request_type', 30)->default('ADJUSTMENT')->after('code')->index();
                }
                if (! Schema::hasColumn('inventory_adjustment_requests', 'reversal_of_id')) {
                    $table->foreignId('reversal_of_id')->nullable()->after('request_type')->constrained('inventory_adjustment_requests')->cascadeOnUpdate()->nullOnDelete();
                }
                if (! Schema::hasColumn('inventory_adjustment_requests', 'inventory_movement_id')) {
                    $table->foreignId('inventory_movement_id')->nullable()->after('reversal_of_id')->constrained('inventory_movements')->cascadeOnUpdate()->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('inventory_movements') && ! Schema::hasColumn('inventory_movements', 'source_inventory_movement_id')) {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->foreignId('source_inventory_movement_id')->nullable()->after('source_service_consumption_line_id')->constrained('inventory_movements')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        if (Schema::hasTable('inventory_receipts') && ! Schema::hasColumn('inventory_receipts', 'original_total_cost')) {
            Schema::table('inventory_receipts', function (Blueprint $table) {
                $table->decimal('original_total_cost', 14, 2)->nullable()->after('total_cost');
            });
            DB::table('inventory_receipts')->whereNull('original_total_cost')->update(['original_total_cost' => DB::raw('total_cost')]);
        }

        if (! Schema::hasTable('inventory_receipt_action_requests')) {
            Schema::create('inventory_receipt_action_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained(indexName: 'fk_inventory_receipt__business_id_876b73c6')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80);
                $table->foreignId('inventory_receipt_id')->constrained('inventory_receipts', indexName: 'fk_inventory_receipt__inventory_receipt__dcfaba58')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('action_type', 30)->index(); // REVERSAL / CORRECTION
                $table->string('status', 20)->default('PENDING')->index();
                $table->text('reason');
                $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'fk_inventory_receipt__requested_by_fdec437a')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'fk_inventory_receipt__reviewed_by_25f1dbdd')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->decimal('supplier_refund_receivable', 14, 2)->default(0);
                $table->timestamps();

                $table->unique(['business_id', 'code'], 'inv_rcpt_action_business_code_uq');
                $table->index(['inventory_receipt_id', 'status'], 'inv_rcpt_action_receipt_status_idx');
            });
        }

        if (! Schema::hasTable('inventory_receipt_action_lines')) {
            Schema::create('inventory_receipt_action_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_receipt_action_request_id')->constrained('inventory_receipt_action_requests', indexName: 'fk_inventory_receipt__inventory_receipt__56dfe00b')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('inventory_receipt_line_id')->constrained('inventory_receipt_lines', indexName: 'fk_inventory_receipt__inventory_receipt__cdfa510d')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('before_purchase_quantity', 14, 4)->nullable();
                $table->decimal('before_purchase_unit_cost', 14, 2)->nullable();
                $table->decimal('corrected_purchase_quantity', 14, 4)->nullable();
                $table->decimal('corrected_purchase_unit_cost', 14, 2)->nullable();
                $table->decimal('quantity_delta', 14, 4)->default(0);
                $table->decimal('value_delta', 14, 2)->default(0);
                $table->timestamps();

                $table->unique(['inventory_receipt_action_request_id', 'inventory_receipt_line_id'], 'inv_rcpt_action_line_uq');
            });
        }

        if (! Schema::hasTable('inventory_receipt_action_refunds')) {
            Schema::create('inventory_receipt_action_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_receipt_action_request_id')->constrained('inventory_receipt_action_requests', indexName: 'fk_inventory_receipt__inventory_receipt__7150ff2a')->cascadeOnUpdate()->cascadeOnDelete();
                $table->date('refund_date');
                $table->foreignId('receiving_account_id')->constrained('chart_of_accounts', indexName: 'fk_inventory_receipt__receiving_account__9950266a')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->string('reference', 180)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'fk_inventory_receipt__created_by_c9bac22a')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->index(['inventory_receipt_action_request_id', 'refund_date'], 'inv_rcpt_action_refund_date_idx');
            });
        }

        if (! Schema::hasTable('inventory_stock_returns')) {
            Schema::create('inventory_stock_returns', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained(indexName: 'fk_inventory_stock_re_business_id_52e72b69')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80);
                $table->foreignId('branch_id')->constrained(indexName: 'fk_inventory_stock_re_branch_id_a1233233')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('supplier_id')->nullable()->constrained('suppliers', indexName: 'fk_inventory_stock_re_supplier_id_1527f2ba')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('inventory_receipt_id')->constrained('inventory_receipts', indexName: 'fk_inventory_stock_re_inventory_receipt__88f6c3b3')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('return_date');
                $table->text('reason');
                $table->string('status', 20)->default('PENDING')->index();
                $table->decimal('total_value', 14, 2)->default(0);
                $table->decimal('ap_credit_amount', 14, 2)->default(0);
                $table->decimal('supplier_refund_receivable', 14, 2)->default(0);
                $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'fk_inventory_stock_re_requested_by_87173bad')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'fk_inventory_stock_re_reviewed_by_af172d3d')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'code'], 'inv_stock_returns_business_code_uq');
                $table->index(['business_id', 'branch_id', 'return_date'], 'inv_stock_returns_branch_date_idx');
                $table->index(['inventory_receipt_id', 'status'], 'inv_stock_returns_receipt_status_idx');
            });
        }

        if (! Schema::hasTable('inventory_stock_return_lines')) {
            Schema::create('inventory_stock_return_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_stock_return_id')->constrained('inventory_stock_returns', indexName: 'fk_inventory_stock_re_inventory_stock_re_4760a039')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('inventory_receipt_line_id')->constrained('inventory_receipt_lines', indexName: 'fk_inventory_stock_re_inventory_receipt__5edfae4e')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products', indexName: 'fk_inventory_stock_re_retail_product_id_2eef0310')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('quantity_returned', 14, 4);
                $table->decimal('unit_cost_snapshot', 14, 4);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();

                $table->unique(['inventory_stock_return_id', 'inventory_receipt_line_id'], 'inv_stock_return_line_uq');
            });
        }

        if (! Schema::hasTable('inventory_stock_return_refunds')) {
            Schema::create('inventory_stock_return_refunds', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_stock_return_id')->constrained('inventory_stock_returns', indexName: 'fk_inventory_stock_re_inventory_stock_re_29b8ca79')->cascadeOnUpdate()->cascadeOnDelete();
                $table->date('refund_date');
                $table->foreignId('receiving_account_id')->constrained('chart_of_accounts', indexName: 'fk_inventory_stock_re_receiving_account__5bf28483')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('amount', 14, 2);
                $table->string('reference', 180)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users', indexName: 'fk_inventory_stock_re_created_by_eeaf5106')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('expense_reversal_requests')) {
            Schema::create('expense_reversal_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained(indexName: 'fk_expense_reversal_r_business_id_3dd88ab0')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80);
                $table->foreignId('expense_id')->constrained('expenses', indexName: 'fk_expense_reversal_r_expense_id_4610ccad')->cascadeOnUpdate()->restrictOnDelete();
                $table->string('status', 20)->default('PENDING')->index();
                $table->text('reason');
                $table->foreignId('requested_by')->nullable()->constrained('users', indexName: 'fk_expense_reversal_r_requested_by_6282acea')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users', indexName: 'fk_expense_reversal_r_reviewed_by_de529e86')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'code'], 'expense_reversal_business_code_uq');
                $table->index(['expense_id', 'status'], 'expense_reversal_expense_status_idx');
            });
        }

        if (Schema::hasTable('chart_of_accounts')) {
            foreach (DB::table('businesses')->pluck('id') as $businessId) {
                DB::table('chart_of_accounts')->updateOrInsert(
                    ['business_id' => $businessId, 'code' => '1104'],
                    [
                        'name' => 'Supplier Refunds Receivable',
                        'account_type' => 'ASSET',
                        'account_group' => 'Current Asset',
                        'normal_balance' => 'DEBIT',
                        'posting_type' => 'POSTING',
                        'cash_flow_classification' => 'OPERATING',
                        'is_opex' => false,
                        'is_cash_account' => false,
                        'is_funding_source' => false,
                        'system_key' => 'SUPPLIER_REFUNDS_RECEIVABLE',
                        'active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }

        $permissions = [
            ['inventory-adjustments', 'REVERSE'],
            ['stock-received', 'REQUEST_CORRECTION'],
            ['stock-received', 'APPROVE_CORRECTION'],
            ['stock-received', 'REQUEST_REVERSAL'],
            ['stock-received', 'APPROVE_REVERSAL'],
            ['stock-received', 'RECEIVE_REFUND'],
            ['stock-returns', 'VIEW'],
            ['stock-returns', 'CREATE'],
            ['stock-returns', 'APPROVE'],
            ['stock-returns', 'RECEIVE_REFUND'],
            ['expenses', 'APPROVE_REVERSAL'],
        ];
        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['module' => $module, 'action' => $action],
                [
                    'code' => $module.'.'.$action,
                    'name' => str($module)->replace('-', ' ')->title().' - '.str($action)->replace('_', ' ')->title(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where(function ($q) {
                $q->where('module', 'stock-returns')
                    ->orWhere(function ($x) { $x->where('module', 'inventory-adjustments')->where('action', 'REVERSE'); })
                    ->orWhere(function ($x) { $x->where('module', 'stock-received')->whereIn('action', ['REQUEST_CORRECTION','APPROVE_CORRECTION','REQUEST_REVERSAL','APPROVE_REVERSAL','RECEIVE_REFUND']); })
                    ->orWhere(function ($x) { $x->where('module', 'expenses')->where('action', 'APPROVE_REVERSAL'); });
            })
            ->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA','ROLE-MGR'])->pluck('id');
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
        Schema::dropIfExists('expense_reversal_requests');
        Schema::dropIfExists('inventory_stock_return_refunds');
        Schema::dropIfExists('inventory_receipt_action_refunds');
        Schema::dropIfExists('inventory_stock_return_lines');
        Schema::dropIfExists('inventory_stock_returns');
        Schema::dropIfExists('inventory_receipt_action_lines');
        Schema::dropIfExists('inventory_receipt_action_requests');

        if (Schema::hasTable('inventory_movements') && Schema::hasColumn('inventory_movements', 'source_inventory_movement_id')) {
            Schema::table('inventory_movements', fn (Blueprint $table) => $table->dropConstrainedForeignId('source_inventory_movement_id'));
        }
        if (Schema::hasTable('inventory_adjustment_requests')) {
            Schema::table('inventory_adjustment_requests', function (Blueprint $table) {
                if (Schema::hasColumn('inventory_adjustment_requests', 'inventory_movement_id')) $table->dropConstrainedForeignId('inventory_movement_id');
                if (Schema::hasColumn('inventory_adjustment_requests', 'reversal_of_id')) $table->dropConstrainedForeignId('reversal_of_id');
                if (Schema::hasColumn('inventory_adjustment_requests', 'request_type')) $table->dropColumn('request_type');
            });
        }
        if (Schema::hasTable('inventory_receipts') && Schema::hasColumn('inventory_receipts', 'original_total_cost')) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->dropColumn('original_total_cost'));
        }

        if (Schema::hasTable('chart_of_accounts')) {
            DB::table('chart_of_accounts')->where('system_key', 'SUPPLIER_REFUNDS_RECEIVABLE')->delete();
        }
    }
};
