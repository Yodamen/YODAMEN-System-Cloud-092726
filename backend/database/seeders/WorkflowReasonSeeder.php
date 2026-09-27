<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\ReferenceGroup;
use App\Models\ReferenceValue;
use App\Services\MasterData\WorkflowReasonCatalog;
use Illuminate\Database\Seeder;

class WorkflowReasonSeeder extends Seeder
{
    public function run(): void
    {
        Business::query()->each(function (Business $business): void {
            $group = ReferenceGroup::query()->firstOrCreate(
                [
                    'business_id' => $business->id,
                    'code' => WorkflowReasonCatalog::GROUP_CODE,
                ],
                [
                    'name' => 'Workflow Reason',
                    'description' => 'Standardized reason categories used by controlled approval, correction, refund and reversal workflows.',
                    'is_system' => true,
                    'active' => true,
                ],
            );

            foreach (WorkflowReasonCatalog::defaults() as $index => [$code, $displayValue, $contexts]) {
                ReferenceValue::query()->firstOrCreate(
                    [
                        'reference_group_id' => $group->id,
                        'code' => $code,
                    ],
                    [
                        'display_value' => $displayValue,
                        'sort_order' => ($index + 1) * 10,
                        'is_system' => true,
                        'active' => true,
                        'metadata' => ['contexts' => array_values($contexts)],
                    ],
                );
            }
        });
    }
}
