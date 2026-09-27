<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RetailPosPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'retail-pos' => ['VIEW', 'CREATE'],
            'inventory-master' => ['VIEW', 'CREATE', 'EDIT'],
            'inventory-adjustments' => ['VIEW', 'CREATE', 'APPROVE', 'ADJUST'],
        ];

        foreach ($catalog as $module => $actions) {
            foreach ($actions as $action) {
                Permission::query()->updateOrCreate(
                    ['module' => $module, 'action' => $action],
                    ['code' => $module.'.'.$action, 'name' => ucwords(str_replace('-', ' ', $module)).' '.ucwords(str_replace('_', ' ', strtolower($action)))],
                );
            }
        }

        $superAdmin = Role::query()->where('code', 'ROLE-SA')->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching(
                Permission::query()->whereIn('module', array_keys($catalog))->pluck('id')->all(),
            );
        }

        $manager = Role::query()->where('code', 'ROLE-MGR')->first();
        if ($manager) {
            $manager->permissions()->syncWithoutDetaching(
                Permission::query()->where(function ($q) {
                    $q->where('module', 'retail-pos')->whereIn('action', ['VIEW', 'CREATE'])
                        ->orWhere(function ($inventory) {
                            $inventory->where('module', 'inventory-master')->whereIn('action', ['VIEW', 'CREATE', 'EDIT']);
                        })->orWhere(function ($adjustments) {
                            $adjustments->where('module', 'inventory-adjustments')->whereIn('action', ['VIEW', 'CREATE', 'APPROVE', 'ADJUST']);
                        });
                })->pluck('id')->all(),
            );
        }

        $reception = Role::query()->where('code', 'ROLE-REC')->first();
        if ($reception) {
            $reception->permissions()->syncWithoutDetaching(
                Permission::query()->where(function ($q) {
                    $q->where('module', 'retail-pos')->whereIn('action', ['VIEW', 'CREATE'])
                        ->orWhere(function ($inventory) {
                            $inventory->where('module', 'inventory-master')->where('action', 'VIEW');
                        });
                })->pluck('id')->all(),
            );
        }
    }
}
