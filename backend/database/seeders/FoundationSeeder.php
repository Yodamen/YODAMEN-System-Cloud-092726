<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class FoundationSeeder extends Seeder
{
    public function run(): void
    {
        $business = Business::query()->updateOrCreate(
            ['code' => 'YDM'],
            [
                'name' => 'YODAMEN Grooming Lounge',
                'legal_name' => null,
                'timezone' => 'Asia/Manila',
                'currency' => 'PHP',
                'status' => 'ACTIVE',
            ]
        );

        Branch::query()->updateOrCreate(
            ['code' => 'YDM-SJC'],
            [
                'business_id' => $business->id,
                'name' => 'San Jose City',
                'short_code' => 'SJC',
                'address' => null,
                'timezone' => 'Asia/Manila',
                'status' => 'ACTIVE',
            ]
        );

        $roles = collect([
            ['code' => 'ROLE-SA', 'name' => 'Super Admin', 'description' => 'Full system access', 'is_super_admin' => true, 'is_system' => true],
            ['code' => 'ROLE-MGR', 'name' => 'Manager', 'description' => 'Management and operations', 'is_super_admin' => false, 'is_system' => true],
            ['code' => 'ROLE-REC', 'name' => 'Reception', 'description' => 'Front desk and sales operations', 'is_super_admin' => false, 'is_system' => true],
            ['code' => 'ROLE-BAR', 'name' => 'Barber', 'description' => 'Employee self-service and assigned work', 'is_super_admin' => false, 'is_system' => true],
        ])->mapWithKeys(function (array $definition) use ($business) {
            $role = Role::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'business_id' => $business->id,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_super_admin' => $definition['is_super_admin'],
                    'is_system' => $definition['is_system'],
                    'active' => true,
                ]
            );

            return [$role->code => $role];
        });

        foreach ($this->permissionCatalog() as [$module, $action]) {
            Permission::query()->updateOrCreate(
                ['module' => $module, 'action' => $action],
                [
                    'code' => $module.'.'.$action,
                    'name' => $this->permissionName($module, $action),
                ]
            );
        }

        $allPermissionIds = Permission::query()->pluck('id')->all();
        $roles['ROLE-SA']->permissions()->sync($allPermissionIds);

        $legacyViewSets = [
            'ROLE-MGR' => [
                'dashboard', 'customers', 'bookings', 'queue', 'visits', 'fulfillment',
                'sales', 'packages', 'service-balances', 'payments', 'employees', 'attendance',
                'daily-compensation', 'commissions', 'payroll', 'payslips', 'ar', 'collections',
                'expenses', 'petty-cash', 'bank-reconciliation', 'cash-management', 'daily-closing',
                'cash-flow', 'financial-statements', 'inventory-dashboard', 'inventory-master',
                'stock-received', 'inventory-movements', 'service-consumption', 'inventory-count',
                'inventory-adjustments', 'reorder', 'report-sales', 'report-customers',
                'report-services', 'report-staff', 'report-hr', 'report-accounting', 'report-inventory',
            ],
            'ROLE-REC' => [
                'dashboard', 'customers', 'bookings', 'queue', 'visits', 'fulfillment',
                'sales', 'packages', 'service-balances', 'payments',
            ],
            'ROLE-BAR' => [
                'dashboard', 'queue', 'fulfillment', 'attendance', 'commissions', 'payslips',
            ],
        ];

        foreach ($legacyViewSets as $roleCode => $modules) {
            $permissionIds = Permission::query()
                ->where('action', 'VIEW')
                ->whereIn('module', $modules)
                ->pluck('id')
                ->all();

            $roles[$roleCode]->permissions()->sync($permissionIds);
        }
    }

    private function permissionCatalog(): array
    {
        $catalog = [
            'dashboard' => ['VIEW'],
            'customers' => ['VIEW', 'CREATE', 'EDIT', 'TOGGLE'],
            'bookings' => ['VIEW', 'CREATE', 'EDIT'],
            'queue' => ['VIEW', 'CREATE', 'EDIT'],
            'visits' => ['VIEW', 'EDIT', 'COMPLETE'],
            'fulfillment' => ['VIEW', 'CREATE', 'EDIT', 'COMPLETE'],
            'servicing' => ['VIEW', 'EDIT', 'COMPLETE'],
            'sales-dashboard' => ['VIEW'],
            'sales' => ['VIEW', 'CREATE', 'EDIT'],
            'packages' => ['VIEW'],
            'service-balances' => ['VIEW'],
            'payments' => ['VIEW'],
            'my-payroll' => ['VIEW'],
            'my-profit' => ['VIEW'],
            'my-attendance' => ['VIEW'],
            'employees' => ['VIEW', 'CREATE', 'EDIT'],
            'attendance' => ['VIEW', 'CREATE', 'EDIT'],
            'daily-compensation' => ['VIEW', 'EDIT'],
            'commissions' => ['VIEW', 'EDIT'],
            'cash-advances' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'payroll' => ['VIEW', 'CREATE', 'APPROVE', 'MARK_PAID'],
            'payslips' => ['VIEW'],
            'leave' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'profit-sharing' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE', 'MARK_PAID'],
            'ar' => ['VIEW'],
            'collections' => ['VIEW'],
            'expenses' => ['VIEW'],
            'petty-cash' => ['VIEW'],
            'bank-reconciliation' => ['VIEW'],
            'cash-management' => ['VIEW'],
            'daily-closing' => ['VIEW'],
            'cash-flow' => ['VIEW'],
            'financial-statements' => ['VIEW'],
            'inventory-dashboard' => ['VIEW'],
            'inventory-master' => ['VIEW'],
            'stock-received' => ['VIEW'],
            'inventory-movements' => ['VIEW'],
            'service-consumption' => ['VIEW'],
            'inventory-count' => ['VIEW'],
            'inventory-adjustments' => ['VIEW'],
            'reorder' => ['VIEW'],
            'report-sales' => ['VIEW'],
            'report-customers' => ['VIEW'],
            'report-services' => ['VIEW'],
            'report-staff' => ['VIEW'],
            'report-hr' => ['VIEW'],
            'report-accounting' => ['VIEW'],
            'report-inventory' => ['VIEW'],
            'business-setup' => ['VIEW', 'EDIT', 'MANAGE_BRANCHES'],
            'branding' => ['VIEW', 'EDIT'],
            'references' => ['VIEW', 'CREATE', 'EDIT', 'TOGGLE'],
            'services-pricing' => ['VIEW', 'CREATE', 'EDIT', 'CHANGE_PRICE'],
            'service-costing' => ['VIEW'],
            'package-setup' => ['VIEW'],
            'commission-rules' => ['VIEW', 'CREATE', 'EDIT', 'TOGGLE'],
            'cash-accounts' => ['VIEW'],
            'coa' => ['VIEW'],
            'accounting-mapping' => ['VIEW'],
            'users' => ['VIEW', 'CREATE', 'EDIT', 'RESET_CODE', 'MANAGE_PERMISSIONS'],
            'audit' => ['VIEW'],
        ];

        $rows = [];
        foreach ($catalog as $module => $actions) {
            foreach ($actions as $action) {
                $rows[] = [$module, $action];
            }
        }

        return $rows;
    }

    private function permissionName(string $module, string $action): string
    {
        $moduleName = str($module)->replace('-', ' ')->title()->toString();
        $actionName = str($action)->replace('_', ' ')->title()->toString();

        return $moduleName.' - '.$actionName;
    }
}
