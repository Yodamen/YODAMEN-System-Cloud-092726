<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

class ProductMasterReferenceSeeder extends Seeder
{
    public function run(): void
    {
        Business::query()->get()->each(function (Business $business) {
            foreach ($this->catalog() as $definition) {
                $group = ReferenceGroup::query()->updateOrCreate(
                    [
                        'business_id' => $business->id,
                        'code' => $definition['code'],
                    ],
                    [
                        'name' => $definition['name'],
                        'description' => $definition['description'] ?? null,
                        'is_system' => true,
                        'active' => true,
                    ],
                );

                foreach ($definition['values'] as $index => [$code, $displayValue]) {
                    ReferenceValue::query()->updateOrCreate(
                        [
                            'reference_group_id' => $group->id,
                            'code' => $code,
                        ],
                        [
                            'display_value' => $displayValue,
                            'sort_order' => ($index + 1) * 10,
                            'is_system' => true,
                            'active' => true,
                        ],
                    );
                }
            }
        });
    }

    private function catalog(): array
    {
        return [
            [
                'code' => 'PRODUCT_CATEGORY',
                'name' => 'Inventory Category',
                'description' => 'Categories for quantity-tracked inventory items used for retail sale or service consumption.',
                'values' => [
                    ['HAIR_CARE', 'Hair Care'],
                    ['SCALP_CARE', 'Scalp Care'],
                    ['STYLING', 'Styling'],
                    ['BEARD_CARE', 'Beard Care'],
                    ['SKIN_CARE', 'Skin Care'],
                    ['GROOMING', 'Grooming Essentials'],
                    ['TOOLS_ACCESSORIES', 'Tools & Accessories'],
                    ['OTHER', 'Other'],
                ],
            ],
            [
                'code' => 'NON_INVENTORY_CATEGORY',
                'name' => 'Non-Inventory Category',
                'description' => 'Categories for internal-use supplies that are expensed and are not quantity-tracked for POS or service stock.',
                'values' => [
                    ['OFFICE_SUPPLIES', 'Office Supplies'],
                    ['CLEANING_SUPPLIES', 'Cleaning Supplies'],
                    ['PANTRY_SUPPLIES', 'Pantry Supplies'],
                    ['MARKETING_MATERIALS', 'Marketing Materials'],
                    ['GENERAL_CONSUMABLES', 'General Consumables'],
                    ['OTHER_NON_INVENTORY', 'Other Non-Inventory'],
                ],
            ],
            [
                'code' => 'ASSET_CATEGORY',
                'name' => 'Asset Category',
                'description' => 'Categories for equipment, furniture, tools, and other controlled business assets.',
                'values' => [
                    ['BARBER_EQUIPMENT', 'Barber Equipment'],
                    ['FURNITURE_FIXTURES', 'Furniture & Fixtures'],
                    ['COMPUTER_IT', 'Computer & IT Equipment'],
                    ['TOOLS_EQUIPMENT', 'Tools & Equipment'],
                    ['APPLIANCES', 'Appliances'],
                    ['OTHER_ASSET', 'Other Asset'],
                ],
            ],
            [
                'code' => 'PRODUCT_UOM',
                'name' => 'Product UOM',
                'description' => 'Units of measure used by Inventory Masterlist and inventory transactions.',
                'values' => [
                    ['PC', 'pc'],
                    ['BOTTLE', 'bottle'],
                    ['TUBE', 'tube'],
                    ['JAR', 'jar'],
                    ['PACK', 'pack'],
                    ['BOX', 'box'],
                    ['SACHET', 'sachet'],
                    ['SET', 'set'],
                    ['ML', 'ml'],
                    ['LITER', 'liter'],
                    ['G', 'g'],
                    ['KG', 'kg'],
                    ['ROLL', 'roll'],
                    ['REAM', 'ream'],
                    ['CASE', 'case'],
                    ['DOZEN', 'dozen'],
                ],
            ],
            [
                'code' => 'SUPPLIER_CHANNEL',
                'name' => 'Supplier Channel',
                'description' => 'Primary communication or ordering channel used for suppliers.',
                'values' => [
                    ['PHONE', 'Phone'],
                    ['EMAIL', 'Email'],
                    ['VIBER', 'Viber'],
                    ['MESSENGER', 'Messenger'],
                    ['WHATSAPP', 'WhatsApp'],
                    ['WECHAT', 'WeChat'],
                    ['OTHER', 'Other'],
                ],
            ],
            [
                'code' => 'PRODUCT_BRAND',
                'name' => 'Product Brand',
                'description' => 'Brand labels used by Inventory Masterlist. Add your own brands as reference options.',
                'values' => [],
            ],
        ];
    }
}
