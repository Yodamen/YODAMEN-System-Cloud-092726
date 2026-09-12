<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class SalesCorePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            ['module' => 'payments', 'action' => 'CREATE', 'name' => 'Payments - Create'],
        ];

        foreach ($definitions as $definition) {
            Permission::query()->updateOrCreate(
                ['module' => $definition['module'], 'action' => $definition['action']],
                ['code' => $definition['module'].'.'.$definition['action'], 'name' => $definition['name']],
            );
        }

        $superAdmin = Role::query()->where('code', 'ROLE-SA')->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching(Permission::query()->pluck('id')->all());
        }

        $advancePermission = Permission::query()->where('code', 'payments.CREATE')->value('id');
        if ($advancePermission) {
            foreach (['ROLE-MGR', 'ROLE-REC'] as $roleCode) {
                $role = Role::query()->where('code', $roleCode)->first();
                if ($role) $role->permissions()->syncWithoutDetaching([$advancePermission]);
            }
        }
    }
}
