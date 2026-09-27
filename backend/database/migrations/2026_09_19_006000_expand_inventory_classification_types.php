<?php

use App\Models\Business;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Business::query()->get()->each(function (Business $business) {
            $inventoryGroup = ReferenceGroup::query()->firstOrCreate(
                ['business_id' => $business->id, 'code' => 'PRODUCT_CATEGORY'],
                [
                    'name' => 'Inventory Category',
                    'description' => 'Categories for quantity-tracked inventory items used for retail sale or service consumption.',
                    'is_system' => true,
                    'active' => true,
                ],
            );

            $inventoryGroup->update([
                'name' => 'Inventory Category',
                'description' => 'Categories for quantity-tracked inventory items used for retail sale or service consumption.',
                'is_system' => true,
                'active' => true,
            ]);

            $this->ensureGroup(
                $business,
                'NON_INVENTORY_CATEGORY',
                'Non-Inventory Category',
                'Categories for internal-use supplies that are expensed and are not quantity-tracked for POS or service stock.',
                [
                    ['OFFICE_SUPPLIES', 'Office Supplies'],
                    ['CLEANING_SUPPLIES', 'Cleaning Supplies'],
                    ['PANTRY_SUPPLIES', 'Pantry Supplies'],
                    ['MARKETING_MATERIALS', 'Marketing Materials'],
                    ['GENERAL_CONSUMABLES', 'General Consumables'],
                    ['OTHER_NON_INVENTORY', 'Other Non-Inventory'],
                ],
            );

            $this->ensureGroup(
                $business,
                'ASSET_CATEGORY',
                'Asset Category',
                'Categories for equipment, furniture, tools, and other controlled business assets.',
                [
                    ['BARBER_EQUIPMENT', 'Barber Equipment'],
                    ['FURNITURE_FIXTURES', 'Furniture & Fixtures'],
                    ['COMPUTER_IT', 'Computer & IT Equipment'],
                    ['TOOLS_EQUIPMENT', 'Tools & Equipment'],
                    ['APPLIANCES', 'Appliances'],
                    ['OTHER_ASSET', 'Other Asset'],
                ],
            );
        });

        if (\Illuminate\Support\Facades\Schema::hasColumn('retail_products', 'stock_type')) {
            \Illuminate\Support\Facades\DB::table('retail_products')
                ->where('stock_type', 'NON_INVENTORY')
                ->update([
                    'selling_price' => 0,
                    'reorder_point' => 0,
                    'commission_eligible' => false,
                ]);
        }
    }

    public function down(): void
    {
        ReferenceGroup::query()->where('code', 'PRODUCT_CATEGORY')->get()->each(function (ReferenceGroup $group) {
            $group->update([
                'name' => 'Product Category',
                'description' => 'Reusable product categories for Product Masterlist, Retail POS, and retail commission rules.',
            ]);
        });

        foreach (['NON_INVENTORY_CATEGORY', 'ASSET_CATEGORY'] as $groupCode) {
            ReferenceGroup::query()->where('code', $groupCode)->get()->each(function (ReferenceGroup $group) {
                $group->values()->where('is_system', true)->delete();
                if (! $group->values()->exists()) {
                    $group->delete();
                }
            });
        }
    }

    private function ensureGroup(
        Business $business,
        string $code,
        string $name,
        string $description,
        array $values,
    ): void {
        $group = ReferenceGroup::query()->updateOrCreate(
            ['business_id' => $business->id, 'code' => $code],
            [
                'name' => $name,
                'description' => $description,
                'is_system' => true,
                'active' => true,
            ],
        );

        foreach ($values as $index => [$valueCode, $displayValue]) {
            ReferenceValue::query()->updateOrCreate(
                ['reference_group_id' => $group->id, 'code' => $valueCode],
                [
                    'display_value' => $displayValue,
                    'sort_order' => ($index + 1) * 10,
                    'is_system' => true,
                    'active' => true,
                ],
            );
        }
    }
};
