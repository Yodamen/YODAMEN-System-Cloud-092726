<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\LeaveType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class HrLeaveSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::query()->where('code', 'YDM')->first();
        if (! $business) {
            return;
        }

        foreach ([
            ['code' => 'VACATION', 'name' => 'Vacation Leave', 'is_paid' => true, 'consumes_balance' => true, 'sort_order' => 10],
            ['code' => 'SICK', 'name' => 'Sick Leave', 'is_paid' => true, 'consumes_balance' => true, 'sort_order' => 20],
            ['code' => 'EMERGENCY', 'name' => 'Emergency Leave', 'is_paid' => false, 'consumes_balance' => false, 'sort_order' => 30],
            ['code' => 'UNPAID', 'name' => 'Unpaid Leave', 'is_paid' => false, 'consumes_balance' => false, 'sort_order' => 40],
        ] as $row) {
            LeaveType::query()->updateOrCreate(
                ['business_id' => $business->id, 'code' => $row['code']],
                [...$row, 'business_id' => $business->id, 'active' => true],
            );
        }

        $permissions = collect([
            ['my-leave', 'VIEW'],
            ['my-leave', 'CREATE'],
            ['my-leave', 'EDIT'],
        ])->map(function (array $row) {
            return Permission::query()->updateOrCreate(
                ['module' => $row[0], 'action' => $row[1]],
                [
                    'code' => $row[0].'.'.$row[1],
                    'name' => str($row[0])->replace('-', ' ')->title().' - '.str($row[1])->title(),
                ],
            );
        });

        $superAdmin = Role::query()->where('business_id', $business->id)->where('is_super_admin', true)->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());
        }

        $barber = Role::query()->where('business_id', $business->id)->where('code', 'ROLE-BAR')->first();
        if ($barber) {
            $barber->permissions()->syncWithoutDetaching($permissions->pluck('id')->all());
        }

        $manager = Role::query()->where('business_id', $business->id)->where('code', 'ROLE-MGR')->first();
        if ($manager) {
            $manager->permissions()->syncWithoutDetaching(
                Permission::query()->whereIn('module', ['my-leave', 'leave'])->pluck('id')->all()
            );
        }
    }
}
