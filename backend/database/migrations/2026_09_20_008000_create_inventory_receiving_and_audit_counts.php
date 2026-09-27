<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_receipts')) {
            Schema::create('inventory_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80);
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->date('received_date');
                $table->string('supplier_name', 180)->nullable();
                $table->string('reference_no', 180)->nullable();
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('POSTED')->index();
                $table->decimal('total_cost', 14, 2)->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('posted_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'code'], 'inventory_receipts_business_code_uq');
                $table->index(['business_id', 'branch_id', 'received_date'], 'inventory_receipts_business_branch_date_idx');
            });
        }

        if (! Schema::hasTable('inventory_receipt_lines')) {
            Schema::create('inventory_receipt_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_receipt_id')->constrained('inventory_receipts')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->unsignedInteger('quantity_received');
                $table->decimal('unit_cost', 14, 2);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();

                $table->index(['retail_product_id', 'inventory_receipt_id'], 'inventory_receipt_lines_product_receipt_idx');
            });
        }

        if (! Schema::hasTable('inventory_counts')) {
            Schema::create('inventory_counts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80);
                $table->foreignId('branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->date('count_date');
                $table->string('scope_type', 30)->default('FULL');
                $table->string('category', 120)->nullable();
                $table->string('status', 20)->default('DRAFT')->index();
                $table->text('notes')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();

                $table->unique(['business_id', 'code'], 'inventory_counts_business_code_uq');
                $table->index(['business_id', 'branch_id', 'count_date'], 'inventory_counts_business_branch_date_idx');
            });
        }

        if (! Schema::hasTable('inventory_count_lines')) {
            Schema::create('inventory_count_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->restrictOnDelete();
                $table->integer('system_quantity_snapshot')->default(0);
                $table->integer('actual_quantity')->nullable();
                $table->integer('variance_quantity')->nullable();
                $table->decimal('unit_cost_snapshot', 14, 2)->default(0);
                $table->text('line_notes')->nullable();
                $table->timestamps();

                $table->unique(['inventory_count_id', 'retail_product_id'], 'inventory_count_lines_count_product_uq');
            });
        }

        $permissions = [
            ['stock-received', 'CREATE'],
            ['inventory-count', 'CREATE'],
            ['inventory-count', 'EDIT'],
            ['inventory-count', 'APPROVE'],
        ];

        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['module' => $module, 'action' => $action],
                [
                    'code' => $module.'.'.$action,
                    'name' => str($module)->replace('-', ' ')->title()->toString().' - '.str($action)->replace('_', ' ')->title()->toString(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        $permissionIds = DB::table('permissions')
            ->where(function ($query) {
                $query->where(function ($q) { $q->where('module', 'stock-received')->whereIn('action', ['VIEW', 'CREATE']); })
                    ->orWhere(function ($q) { $q->where('module', 'inventory-count')->whereIn('action', ['VIEW', 'CREATE', 'EDIT', 'APPROVE']); });
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
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('inventory_receipt_lines');
        Schema::dropIfExists('inventory_receipts');
    }
};
