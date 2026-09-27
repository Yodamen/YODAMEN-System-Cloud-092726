<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('retail_product_approval_requests')) {
            Schema::create('retail_product_approval_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->cascadeOnDelete();
                $table->string('request_type', 20)->default('UPDATE');
                $table->json('proposed_data');
                $table->string('status', 20)->default('PENDING')->index();
                $table->foreignId('requested_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->text('approval_notes')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'status'], 'retail_product_approval_business_status_idx');
                $table->index(['retail_product_id', 'status'], 'retail_product_approval_product_status_idx');
            });
        }

        DB::table('permissions')->updateOrInsert(
            ['module' => 'inventory-master', 'action' => 'APPROVE'],
            [
                'code' => 'inventory-master.APPROVE',
                'name' => 'Inventory Master - Approve',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $permissionId = DB::table('permissions')
            ->where('module', 'inventory-master')
            ->where('action', 'APPROVE')
            ->value('id');

        if ($permissionId) {
            $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('module', 'inventory-master')
            ->where('action', 'APPROVE')
            ->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Schema::dropIfExists('retail_product_approval_requests');
    }
};
