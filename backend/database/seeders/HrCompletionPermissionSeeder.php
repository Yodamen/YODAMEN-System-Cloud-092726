<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class HrCompletionPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $catalog = [
            'my-attendance' => ['VIEW'],
            'my-leave' => ['VIEW', 'CREATE', 'EDIT'],
            'my-payroll' => ['VIEW'],
            'employees' => ['VIEW', 'CREATE', 'EDIT'],
            'attendance' => ['VIEW', 'CREATE', 'EDIT'],
            'leave' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'daily-compensation' => ['VIEW', 'EDIT'],
            'commissions' => ['VIEW', 'EDIT'],
            'cash-advances' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'payroll' => ['VIEW', 'CREATE', 'APPROVE', 'MARK_PAID'],
            'payslips' => ['VIEW'],
        ];

        $permissionIdsByModule = [];
        foreach ($catalog as $module => $actions) {
            foreach ($actions as $action) {
                $permission = Permission::query()->updateOrCreate(
                    ['module' => $module, 'action' => $action],
                    [
                        'code' => $module.'.'.$action,
                        'name' => str($module)->replace('-', ' ')->title().' - '.str($action)->replace('_', ' ')->title(),
                    ],
                );
                $permissionIdsByModule[$module][] = $permission->id;
            }
        }

        $superAdmin = Role::query()->where('is_super_admin', true)->first();
        if ($superAdmin) {
            $superAdmin->permissions()->syncWithoutDetaching(
                collect($permissionIdsByModule)->flatten()->all()
            );
        }

        $manager = Role::query()->where('code', 'ROLE-MGR')->first();
        if ($manager) {
            $manager->permissions()->syncWithoutDetaching(
                collect([
                    'my-attendance', 'my-leave', 'my-payroll', 'employees', 'attendance', 'leave',
                    'daily-compensation', 'commissions', 'cash-advances', 'payroll', 'payslips',
                ])->flatMap(fn (string $module) => $permissionIdsByModule[$module] ?? [])->all()
            );
        }

        $barber = Role::query()->where('code', 'ROLE-BAR')->first();
        if ($barber) {
            $barber->permissions()->syncWithoutDetaching(
                collect(['my-attendance', 'my-leave', 'my-payroll'])
                    ->flatMap(fn (string $module) => $permissionIdsByModule[$module] ?? [])->all()
            );
        }
    }
}
