<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class CheckoutSettlementPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $salesEdit = Permission::query()->where('code', 'sales.EDIT')->value('id');
        $paymentCreate = Permission::query()->where('code', 'payments.CREATE')->value('id');

        foreach (['ROLE-MGR', 'ROLE-REC'] as $roleCode) {
            $role = Role::query()->where('code', $roleCode)->first();
            if ($role) {
                $ids = array_values(array_filter([$salesEdit, $paymentCreate]));
                if ($ids) $role->permissions()->syncWithoutDetaching($ids);
            }
        }

        $superAdmin = Role::query()->where('code', 'ROLE-SA')->first();
        if ($superAdmin) $superAdmin->permissions()->syncWithoutDetaching(Permission::query()->pluck('id')->all());
    }
}
