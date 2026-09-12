<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class ServicingPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $viewId = Permission::query()->where('code', 'servicing.VIEW')->value('id');
        if (! $viewId) return;

        foreach (['ROLE-MGR', 'ROLE-REC', 'ROLE-BAR'] as $roleCode) {
            $role = Role::query()->where('code', $roleCode)->first();
            if ($role) $role->permissions()->syncWithoutDetaching([$viewId]);
        }
    }
}
