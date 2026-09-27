<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_adjustment_requests')) {
            Schema::create('inventory_adjustment_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 40)->unique();
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->integer('quantity_change');
                $table->text('reason');
                $table->string('status', 20)->default('PENDING')->index();
                $table->foreignId('requested_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status'], 'inventory_adjustment_request_business_status_idx');
                $table->index(['retail_product_id', 'status'], 'inventory_adjustment_request_product_status_idx');
            });
        }

        if (! Schema::hasTable('service_consumption_recipes')) {
            Schema::create('service_consumption_recipes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('service_id')->constrained('services')->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'service_id'], 'service_consumption_recipe_business_service_unique');
            });
        }

        if (! Schema::hasTable('service_consumption_recipe_lines')) {
            Schema::create('service_consumption_recipe_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('service_consumption_recipe_id')->constrained('service_consumption_recipes')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('quantity_per_service', 14, 4);
                $table->string('basic_uom_snapshot', 40);
                $table->decimal('unit_cost_snapshot', 14, 4)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['service_consumption_recipe_id', 'retail_product_id'], 'service_consumption_recipe_item_unique');
            });
        }

        $permissions = [
            ['inventory-adjustments', 'CREATE'],
            ['inventory-adjustments', 'APPROVE'],
            ['service-consumption', 'CREATE'],
            ['service-consumption', 'EDIT'],
        ];

        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['module' => $module, 'action' => $action],
                [
                    'code' => $module.'.'.$action,
                    'name' => ucwords(str_replace('-', ' ', $module)).' '.ucwords(strtolower($action)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }

        $permissionIds = DB::table('permissions')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('module', 'inventory-adjustments')->whereIn('action', ['VIEW', 'CREATE', 'APPROVE']);
                })->orWhere(function ($q) {
                    $q->where('module', 'service-consumption')->whereIn('action', ['VIEW', 'CREATE', 'EDIT']);
                });
            })
            ->pluck('id');

        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->where(function ($query) {
                $query->where('module', 'inventory-adjustments')->whereIn('action', ['CREATE', 'APPROVE'])
                    ->orWhere(function ($q) {
                        $q->where('module', 'service-consumption')->whereIn('action', ['CREATE', 'EDIT']);
                    });
            })
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('service_consumption_recipe_lines');
        Schema::dropIfExists('service_consumption_recipes');
        Schema::dropIfExists('inventory_adjustment_requests');
    }
};
