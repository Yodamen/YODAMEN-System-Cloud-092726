<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('retail_products', 'purchase_uom')) {
            Schema::table('retail_products', function (Blueprint $table) {
                $table->string('purchase_uom', 40)->nullable()->after('unit');
                $table->decimal('purchase_to_basic_factor', 14, 6)->default(1)->after('purchase_uom');
                $table->boolean('retail_available')->default(false)->after('purchase_to_basic_factor')->index();
            });
        }

        DB::table('retail_products')->whereNull('purchase_uom')->update(['purchase_uom' => DB::raw('unit')]);
        DB::table('retail_products')->where('purchase_to_basic_factor', '<=', 0)->update(['purchase_to_basic_factor' => 1]);
        DB::table('retail_products')->where(function ($q) {
            $q->whereNull('stock_type')->orWhere('stock_type', 'INVENTORY');
        })->update(['retail_available' => true]);
        DB::table('retail_products')->whereIn('stock_type', ['NON_INVENTORY', 'ASSET'])->update(['retail_available' => false]);

        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80);
                $table->string('company_name', 180);
                $table->string('contact_person', 180)->nullable();
                $table->string('contact_number', 120)->nullable();
                $table->string('channel', 120)->nullable();
                $table->text('address')->nullable();
                $table->string('store_link', 1000)->nullable();
                $table->string('status', 20)->default('ACTIVE')->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();
                $table->unique(['business_id', 'code'], 'suppliers_business_code_uq');
                $table->index(['business_id', 'status', 'company_name'], 'suppliers_business_status_name_idx');
            });
        }

        if (! Schema::hasTable('supplier_items')) {
            Schema::create('supplier_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreignId('retail_product_id')->constrained('retail_products')->cascadeOnUpdate()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['supplier_id', 'retail_product_id'], 'supplier_items_supplier_product_uq');
            });
        }

        if (! Schema::hasColumn('inventory_receipts', 'supplier_id')) {
            Schema::table('inventory_receipts', function (Blueprint $table) {
                $table->foreignId('supplier_id')->nullable()->after('received_date')->constrained('suppliers')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('inventory_receipt_lines', 'purchase_quantity')) {
            Schema::table('inventory_receipt_lines', function (Blueprint $table) {
                $table->decimal('purchase_quantity', 14, 4)->nullable()->after('retail_product_id');
                $table->string('purchase_uom', 40)->nullable()->after('purchase_quantity');
                $table->decimal('conversion_factor', 14, 6)->default(1)->after('purchase_uom');
                $table->decimal('purchase_unit_cost', 14, 2)->nullable()->after('conversion_factor');
            });
        }

        DB::table('inventory_receipt_lines')->whereNull('purchase_quantity')->update([
            'purchase_quantity' => DB::raw('quantity_received'),
            'conversion_factor' => 1,
            'purchase_unit_cost' => DB::raw('unit_cost'),
        ]);

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $groupId = DB::table('reference_groups')->where('business_id', $businessId)->where('code', 'SUPPLIER_CHANNEL')->value('id');
            if (! $groupId) {
                $groupId = DB::table('reference_groups')->insertGetId([
                    'business_id' => $businessId,
                    'code' => 'SUPPLIER_CHANNEL',
                    'name' => 'Supplier Channel',
                    'description' => 'Primary communication or ordering channel used for suppliers.',
                    'is_system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            foreach ([['PHONE','Phone'],['EMAIL','Email'],['VIBER','Viber'],['MESSENGER','Messenger'],['WHATSAPP','WhatsApp'],['WECHAT','WeChat'],['OTHER','Other']] as $index => [$code, $value]) {
                DB::table('reference_values')->updateOrInsert(
                    ['reference_group_id' => $groupId, 'code' => $code],
                    ['display_value' => $value, 'sort_order' => ($index + 1) * 10, 'is_system' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
            $uomGroup = DB::table('reference_groups')->where('business_id', $businessId)->where('code', 'PRODUCT_UOM')->value('id');
            if ($uomGroup) {
                foreach ([['ML','ml'],['LITER','liter'],['G','g'],['KG','kg'],['ROLL','roll'],['REAM','ream'],['CASE','case'],['DOZEN','dozen']] as $index => [$code, $value]) {
                    DB::table('reference_values')->updateOrInsert(
                        ['reference_group_id' => $uomGroup, 'code' => $code],
                        ['display_value' => $value, 'sort_order' => 100 + (($index + 1) * 10), 'is_system' => true, 'active' => true, 'created_at' => now(), 'updated_at' => now()]
                    );
                }
            }
        }

        foreach ([['supplier-master','VIEW'],['supplier-master','CREATE'],['supplier-master','EDIT']] as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['module' => $module, 'action' => $action],
                ['code' => $module.'.'.$action, 'name' => 'Supplier Masterlist - '.ucfirst(strtolower($action)), 'created_at' => now(), 'updated_at' => now()]
            );
        }
        $permissionIds = DB::table('permissions')->where('module', 'supplier-master')->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])->pluck('id');
        foreach ($roleIds as $roleId) foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId], ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('inventory_receipts', 'supplier_id')) {
            Schema::table('inventory_receipts', fn (Blueprint $table) => $table->dropConstrainedForeignId('supplier_id'));
        }
        Schema::dropIfExists('supplier_items');
        Schema::dropIfExists('suppliers');
        foreach (['purchase_quantity','purchase_uom','conversion_factor','purchase_unit_cost'] as $column) {
            if (Schema::hasColumn('inventory_receipt_lines', $column)) Schema::table('inventory_receipt_lines', fn (Blueprint $table) => $table->dropColumn($column));
        }
        foreach (['purchase_uom','purchase_to_basic_factor','retail_available'] as $column) {
            if (Schema::hasColumn('retail_products', $column)) Schema::table('retail_products', fn (Blueprint $table) => $table->dropColumn($column));
        }
    }
};
