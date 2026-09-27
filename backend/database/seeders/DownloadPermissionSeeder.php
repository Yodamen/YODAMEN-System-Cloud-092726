<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class DownloadPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $modules = [
            'customers','bookings','service-balances','sales','retail-pos','sales-dashboard','collections',
            'cash-deposits','expenses','petty-cash','attendance','daily-compensation','commissions',
            'cash-advances','payroll','payslips','inventory-master','stock-received','stock-returns',
            'service-consumption','inventory-count','inventory-adjustments','coa','general-ledger',
            'trial-balance','cash-management','cash-flow','financial-statements','ar','ap',
        ];

        foreach ($modules as $module) {
            Permission::query()->updateOrCreate(
                ['module' => $module, 'action' => 'DOWNLOAD'],
                [
                    'code' => $module.'.DOWNLOAD',
                    'name' => str($module)->replace('-', ' ')->title().' - Download',
                ],
            );
        }

        $roles = Role::query()->with('permissions:id,module,action')->get();
        foreach ($roles as $role) {
            if ($role->is_super_admin) {
                $role->permissions()->syncWithoutDetaching(Permission::query()->whereIn('module', $modules)->where('action', 'DOWNLOAD')->pluck('id')->all());
                continue;
            }

            $viewModules = $role->permissions
                ->where('action', 'VIEW')
                ->pluck('module')
                ->intersect($modules)
                ->values();

            if ($viewModules->isEmpty()) continue;

            $role->permissions()->syncWithoutDetaching(
                Permission::query()->whereIn('module', $viewModules)->where('action', 'DOWNLOAD')->pluck('id')->all(),
            );
        }
    }
}
