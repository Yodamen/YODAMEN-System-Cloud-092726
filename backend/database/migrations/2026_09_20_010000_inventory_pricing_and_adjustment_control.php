<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retail_product_prices')) {
            Schema::create('retail_product_prices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->cascadeOnDelete();
                $table->decimal('selling_price', 14, 2);
                $table->date('effective_from');
                $table->date('effective_until')->nullable();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['retail_product_id', 'effective_from'], 'retail_product_prices_effective_uq');
                $table->index(['business_id', 'effective_from'], 'retail_product_prices_business_date_idx');
            });
        }

        $existing = DB::table('retail_product_prices')->pluck('retail_product_id')->all();
        DB::table('retail_products')
            ->whereNotIn('id', $existing ?: [0])
            ->orderBy('id')
            ->get(['id', 'business_id', 'selling_price', 'created_by', 'created_at'])
            ->each(function ($product) {
                $effectiveFrom = $product->created_at ? substr((string) $product->created_at, 0, 10) : now()->toDateString();
                DB::table('retail_product_prices')->insert([
                    'business_id' => $product->business_id,
                    'retail_product_id' => $product->id,
                    'selling_price' => $product->selling_price ?? 0,
                    'effective_from' => $effectiveFrom,
                    'effective_until' => null,
                    'status' => 'ACTIVE',
                    'created_by' => $product->created_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('permissions')->updateOrInsert(
            ['module' => 'inventory-adjustments', 'action' => 'ADJUST'],
            [
                'code' => 'inventory-adjustments.ADJUST',
                'name' => 'Inventory Adjustments - Adjust',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $permissionId = DB::table('permissions')
            ->where('module', 'inventory-adjustments')
            ->where('action', 'ADJUST')
            ->value('id');

        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('module', 'inventory-adjustments')
            ->where('action', 'ADJUST')
            ->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::dropIfExists('retail_product_prices');
    }
};
