<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 70)->unique();
            $table->string('rule_name', 160);
            $table->string('discount_code', 40);
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('applies_to', 30)->default('ALL');
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->string('customer_type', 30)->nullable();
            $table->string('item_scope', 30)->default('ALL');
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('service_category_reference_id')->nullable()->constrained('reference_values')->cascadeOnUpdate()->nullOnDelete();
            $table->string('calculation_type', 30)->default('PERCENTAGE');
            $table->decimal('rate_value', 12, 4);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->integer('priority')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'discount_code', 'status'], 'discount_rules_code_status_idx');
            $table->index(['business_id', 'effective_from', 'effective_until'], 'discount_rules_effective_idx');
            $table->index(['business_id', 'applies_to', 'item_scope'], 'discount_rules_scope_idx');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('discount_amount', 12, 2)->default(0)->after('gross_amount');
            $table->decimal('net_amount', 12, 2)->default(0)->after('discount_amount');
            $table->string('discount_code_snapshot', 40)->nullable()->after('net_amount');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('discount_rule_id')->nullable()->after('line_total')->constrained('discount_rules')->cascadeOnUpdate()->nullOnDelete();
            $table->string('discount_code_snapshot', 40)->nullable()->after('discount_rule_id');
            $table->string('discount_rule_name_snapshot', 160)->nullable()->after('discount_code_snapshot');
            $table->string('discount_calculation_type_snapshot', 30)->nullable()->after('discount_rule_name_snapshot');
            $table->decimal('discount_rate_value_snapshot', 12, 4)->nullable()->after('discount_calculation_type_snapshot');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_rate_value_snapshot');
            $table->decimal('net_line_total', 12, 2)->default(0)->after('discount_amount');
        });

        Schema::table('package_enrollments', function (Blueprint $table) {
            $table->decimal('package_gross_price_snapshot', 12, 2)->default(0)->after('package_price_snapshot');
        });

        DB::table('sales')->update([
            'net_amount' => DB::raw('gross_amount'),
        ]);
        DB::table('sale_items')->update([
            'net_line_total' => DB::raw('line_total'),
        ]);
        DB::table('package_enrollments')->update([
            'package_gross_price_snapshot' => DB::raw('package_price_snapshot'),
        ]);

        $now = now();
        foreach (['VIEW', 'CREATE', 'EDIT', 'TOGGLE'] as $action) {
            DB::table('permissions')->updateOrInsert(
                ['module' => 'discount-rules', 'action' => $action],
                [
                    'code' => 'discount-rules.'.$action,
                    'name' => 'Discount Rules - '.str($action)->replace('_', ' ')->title()->toString(),
                    'description' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->where('module', 'discount-rules')
            ->pluck('id');
        $superAdminRoleIds = DB::table('roles')
            ->where('is_super_admin', true)
            ->pluck('id');

        foreach ($superAdminRoleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where('module', 'discount-rules')
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::table('package_enrollments', function (Blueprint $table) {
            $table->dropColumn('package_gross_price_snapshot');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('discount_rule_id');
            $table->dropColumn([
                'discount_code_snapshot',
                'discount_rule_name_snapshot',
                'discount_calculation_type_snapshot',
                'discount_rate_value_snapshot',
                'discount_amount',
                'net_line_total',
            ]);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['discount_amount', 'net_amount', 'discount_code_snapshot']);
        });

        Schema::dropIfExists('discount_rules');
    }
};
