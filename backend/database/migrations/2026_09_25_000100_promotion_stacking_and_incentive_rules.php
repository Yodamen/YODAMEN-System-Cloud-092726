<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('discount_rules', 'stackable')) {
                $table->boolean('stackable')->default(true)->after('priority');
            }
        });
        Schema::table('sales', function (Blueprint $table) {
            if (! Schema::hasColumn('sales', 'promotion_codes_snapshot')) {
                $table->json('promotion_codes_snapshot')->nullable()->after('discount_code_snapshot');
            }
        });
        Schema::table('sale_items', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_items', 'promotion_applications')) {
                $table->json('promotion_applications')->nullable()->after('discount_amount');
            }
        });
        if (! Schema::hasTable('incentive_rules')) {
            Schema::create('incentive_rules', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('rule_name', 160);
                $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
                $table->enum('metric', ['SALE_NET','SERVICE_NET','RETAIL_NET','ITEM_COUNT'])->default('SALE_NET');
                $table->enum('target_scope', ['ALL','PERSONNEL'])->default('ALL');
                $table->foreignId('personnel_id')->nullable()->constrained('personnel')->cascadeOnUpdate()->nullOnDelete();
                $table->decimal('threshold_value', 12, 2)->default(0);
                $table->enum('reward_type', ['FIXED','PERCENTAGE'])->default('FIXED');
                $table->decimal('reward_value', 12, 4);
                $table->date('effective_from');
                $table->date('effective_until')->nullable();
                $table->integer('priority')->default(0);
                $table->boolean('stackable')->default(true);
                $table->enum('status', ['ACTIVE','INACTIVE'])->default('ACTIVE');
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id','code']);
                $table->index(['business_id','status','effective_from']);
            });
        }
        Schema::table('commission_earnings', function (Blueprint $table) {
            if (! Schema::hasColumn('commission_earnings', 'incentive_rule_id')) {
                $table->foreignId('incentive_rule_id')->nullable()->after('commission_rule_id')->constrained('incentive_rules')->cascadeOnUpdate()->nullOnDelete();
            }
        });

        if (Schema::hasTable('chart_of_accounts') && Schema::hasTable('businesses')) {
            foreach (DB::table('businesses')->pluck('id') as $businessId) {
                $exists = DB::table('chart_of_accounts')
                    ->where('business_id', $businessId)
                    ->where('system_key', 'STAFF_INCENTIVES')
                    ->exists();
                if (! $exists) {
                    DB::table('chart_of_accounts')->insert([
                        'business_id' => $businessId,
                        'code' => '6006',
                        'name' => 'Staff Incentives',
                        'account_type' => 'OPERATING_EXPENSE',
                        'account_group' => 'Payroll',
                        'normal_balance' => 'DEBIT',
                        'posting_type' => 'POSTING',
                        'cash_flow_classification' => 'OPERATING',
                        'is_opex' => true,
                        'is_cash_account' => false,
                        'system_key' => 'STAFF_INCENTIVES',
                        'active' => true,
                        'notes' => 'System account for automated Incentive Rules earnings.',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        foreach (['VIEW','CREATE','EDIT','TOGGLE'] as $action) {
            DB::table('permissions')->updateOrInsert(
                ['module' => 'incentive-rules', 'action' => $action],
                ['code' => 'incentive-rules.'.$action, 'name' => 'Incentive Rules - '.str($action)->replace('_',' ')->title()->toString(), 'description' => null, 'updated_at' => now(), 'created_at' => now()]
            );
        }
        $roleIds = DB::table('roles')->whereIn('name', ['Super Admin','Manager'])->pluck('id');
        $permissionIds = DB::table('permissions')->where('module','incentive-rules')->pluck('id');
        foreach ($roleIds as $roleId) foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(['role_id'=>$roleId,'permission_id'=>$permissionId], ['updated_at'=>now(),'created_at'=>now()]);
        }
    }

    public function down(): void
    {
        Schema::table('commission_earnings', function (Blueprint $table) {
            if (Schema::hasColumn('commission_earnings','incentive_rule_id')) $table->dropConstrainedForeignId('incentive_rule_id');
        });
        Schema::dropIfExists('incentive_rules');
        Schema::table('sale_items', function (Blueprint $table) { if (Schema::hasColumn('sale_items','promotion_applications')) $table->dropColumn('promotion_applications'); });
        Schema::table('sales', function (Blueprint $table) { if (Schema::hasColumn('sales','promotion_codes_snapshot')) $table->dropColumn('promotion_codes_snapshot'); });
        Schema::table('discount_rules', function (Blueprint $table) { if (Schema::hasColumn('discount_rules','stackable')) $table->dropColumn('stackable'); });
    }
};
