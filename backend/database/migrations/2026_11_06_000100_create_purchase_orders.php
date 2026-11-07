<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('code', 60);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('status', 24)->default('DRAFT');
            $table->string('reference_no', 180)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
            $table->unique(['business_id', 'code'], 'po_business_code_uq');
            $table->index(['business_id', 'branch_id', 'status'], 'po_business_branch_status_idx');
        });
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('retail_product_id')->constrained()->restrictOnDelete();
            $table->decimal('purchase_quantity', 14, 4);
            $table->string('purchase_uom', 40);
            $table->decimal('conversion_factor', 14, 6);
            $table->decimal('purchase_unit_cost', 14, 2);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
            $table->unique(['purchase_order_id', 'retail_product_id'], 'po_line_product_uq');
        });
        Schema::table('inventory_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
        });
        Schema::table('inventory_receipt_lines', function (Blueprint $table) {
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
        });
        foreach (['VIEW','CREATE','EDIT','SUBMIT','APPROVE','CANCEL','RECEIVE'] as $action) {
            DB::table('permissions')->updateOrInsert(
                ['module'=>'purchasing','action'=>$action],
                ['code'=>'purchasing.'.$action, 'name'=>'Purchasing - '.ucfirst(strtolower($action)), 'created_at'=>now(), 'updated_at'=>now()]
            );
        }
        $permissions = DB::table('permissions')->where('module', 'purchasing')->pluck('id');
        $roles = DB::table('roles')->whereIn('code', ['ROLE-SA','ROLE-MGR'])->pluck('id');
        foreach ($roles as $roleId) foreach ($permissions as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id'=>$roleId,'permission_id'=>$permissionId],
                ['created_at'=>now(),'updated_at'=>now()]
            );
        }
    }

    public function down(): void
    {
        // Explicit dependency-safe rollback: preserves no posted production data by itself.
        Schema::table('inventory_receipt_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_order_line_id'));
        Schema::table('inventory_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('purchase_order_id'));
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        $ids = DB::table('permissions')->where('module', 'purchasing')->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id',$ids)->delete();
        DB::table('permissions')->where('module', 'purchasing')->delete();
    }
};
