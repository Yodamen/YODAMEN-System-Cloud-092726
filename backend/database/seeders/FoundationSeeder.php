<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Business;
use App\Models\CustomerTypeCriterion;
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

        // Starter criteria are created only for a brand-new business. Once a client
        // configures Customer Mix, reseeding must never overwrite or re-add types.
        if (! CustomerTypeCriterion::query()->where('business_id', $business->id)->exists()) {
            foreach ([
                ['code' => 'NEW', 'name' => 'New', 'color' => '#9A9388', 'min_completed_visits' => 0, 'sort_order' => 10],
                ['code' => 'RETURNEE', 'name' => 'Returnee', 'color' => '#665C52', 'min_completed_visits' => 2, 'sort_order' => 20],
                ['code' => 'REGULAR', 'name' => 'Regular', 'color' => '#8A6A3E', 'min_completed_visits' => 3, 'sort_order' => 30],
                ['code' => 'LOYAL', 'name' => 'Loyal', 'color' => '#242424', 'min_completed_visits' => 11, 'sort_order' => 40],
            ] as $criterion) {
                CustomerTypeCriterion::query()->create([
                    'business_id' => $business->id,
                    ...$criterion,
                    'active' => true,
                ]);
            }
        }

        // Workflow reasons are foundational controls used by approval/correction/reversal flows.
        // Seed them after the business exists so fresh/test databases and future deployments
        // receive the same standardized catalog without overwriting administrator changes.
        $this->call(WorkflowReasonSeeder::class);

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
                'dashboard', 'customers', 'customer-type-criteria', 'customer-class-criteria', 'bookings', 'queue', 'visits', 'fulfillment', 'servicing',
                'sales-dashboard', 'sales', 'packages', 'service-balances', 'payments', 'employees', 'attendance',
                'daily-compensation', 'commissions', 'payroll', 'payslips', 'ar', 'ap', 'collections',
                'expenses', 'petty-cash', 'bank-reconciliation', 'cash-management', 'cash-deposits', 'daily-closing',
                'cash-flow', 'financial-statements', 'inventory-dashboard', 'inventory-master', 'supplier-master',
                'stock-received', 'stock-returns', 'inventory-movements', 'service-consumption', 'inventory-count',
                'inventory-adjustments', 'reorder', 'report-sales', 'report-customers',
                'report-services', 'report-staff', 'report-hr', 'report-accounting', 'report-inventory',
            ],
            'ROLE-REC' => [
                'dashboard', 'customers', 'bookings', 'queue', 'visits', 'fulfillment', 'servicing',
                'sales-dashboard', 'sales', 'packages', 'service-balances', 'payments',
            ],
            'ROLE-BAR' => [
                'dashboard', 'queue', 'fulfillment', 'servicing', 'my-attendance', 'my-leave', 'my-payroll', 'commissions',
            ],
        ];

        foreach ($legacyViewSets as $roleCode => $modules) {
            $permissionIds = Permission::query()
                ->where('action', 'VIEW')
                ->whereIn('module', $modules)
                ->pluck('id')
                ->all();

            $roles[$roleCode]->permissions()->syncWithoutDetaching($permissionIds);
        }

        $myProfitPermissionId = Permission::query()
            ->where('module', 'my-profit')
            ->where('action', 'VIEW')
            ->value('id');
        if ($myProfitPermissionId) {
            foreach ($roles as $role) {
                $role->permissions()->syncWithoutDetaching([$myProfitPermissionId]);
            }
        }


        $managerInventoryPermissionIds = Permission::query()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('module', 'stock-received')->whereIn('action', ['VIEW', 'CREATE', 'REQUEST_CORRECTION', 'APPROVE_CORRECTION', 'REQUEST_REVERSAL', 'APPROVE_REVERSAL', 'RECEIVE_REFUND']);
                })->orWhere(function ($q) {
                    $q->where('module', 'stock-returns')->whereIn('action', ['VIEW', 'CREATE', 'APPROVE', 'RECEIVE_REFUND']);
                })->orWhere(function ($q) {
                    $q->where('module', 'supplier-master')->whereIn('action', ['VIEW', 'CREATE', 'EDIT']);
                })->orWhere(function ($q) {
                    $q->where('module', 'inventory-master')->whereIn('action', ['VIEW', 'CREATE', 'EDIT', 'APPROVE']);
                })->orWhere(function ($q) {
                    $q->where('module', 'inventory-count')->whereIn('action', ['VIEW', 'CREATE', 'EDIT', 'APPROVE']);
                })->orWhere(function ($q) {
                    $q->where('module', 'service-consumption')->whereIn('action', ['VIEW', 'CREATE', 'EDIT']);
                })->orWhere(function ($q) {
                    $q->where('module', 'inventory-adjustments')->whereIn('action', ['VIEW', 'CREATE', 'APPROVE', 'ADJUST', 'REVERSE']);
                });
            })
            ->pluck('id')
            ->all();
        $roles['ROLE-MGR']->permissions()->syncWithoutDetaching($managerInventoryPermissionIds);

        $managerAccountingPermissionIds = Permission::query()
            ->where(function ($query) {
                $query->where('module', 'expenses')
                    ->orWhere('module', 'coa')
                    ->orWhereIn('module', [
                        'petty-cash',
                        'ap',
                        'general-ledger',
                        'trial-balance',
                        'cash-management',
                        'cash-deposits',
                        'cash-flow',
                        'daily-closing',
                        'financial-statements',
                        'bank-reconciliation',
                        'profit-sharing',
                        'accounting-mapping',
                    ]);
            })
            ->pluck('id')
            ->all();
        $roles['ROLE-MGR']->permissions()->syncWithoutDetaching($managerAccountingPermissionIds);

        $managerReversalApprovalIds = Permission::query()
            ->where(function ($query) {
                $query->where(function ($q) { $q->where('module', 'petty-cash')->where('action', 'APPROVE_REVERSAL'); })
                    ->orWhere(function ($q) { $q->where('module', 'cash-management')->where('action', 'APPROVE_REVERSAL'); })
                    ->orWhere(function ($q) { $q->where('module', 'cash-deposits')->where('action', 'APPROVE_REVERSAL'); })
                    ->orWhere(function ($q) { $q->where('module', 'payroll')->where('action', 'APPROVE_REVERSAL'); });
            })
            ->pluck('id')
            ->all();
        $roles['ROLE-MGR']->permissions()->syncWithoutDetaching($managerReversalApprovalIds);

        $this->call(ChartOfAccountsSeeder::class);
    }

    private function permissionCatalog(): array
    {
        $catalog = [
            'dashboard' => ['VIEW'],
            'customers' => ['VIEW', 'CREATE', 'EDIT', 'TOGGLE'],
            'customer-type-criteria' => ['VIEW', 'EDIT'],
            'customer-class-criteria' => ['VIEW', 'EDIT'],
            'bookings' => ['VIEW', 'CREATE', 'EDIT'],
            'queue' => ['VIEW', 'CREATE', 'EDIT'],
            'visits' => ['VIEW', 'EDIT', 'COMPLETE'],
            'fulfillment' => ['VIEW', 'CREATE', 'EDIT', 'COMPLETE'],
            'servicing' => ['VIEW', 'EDIT', 'COMPLETE'],
            'sales-dashboard' => ['VIEW'],
            'sales' => ['VIEW', 'CREATE', 'EDIT'],
            'packages' => ['VIEW'],
            'service-balances' => ['VIEW'],
            'payments' => ['VIEW', 'CREATE'],
            'my-payroll' => ['VIEW'],
            'my-profit' => ['VIEW'],
            'my-attendance' => ['VIEW'],
            'my-leave' => ['VIEW', 'CREATE', 'EDIT'],
            'employees' => ['VIEW', 'CREATE', 'EDIT'],
            'attendance' => ['VIEW', 'CREATE', 'EDIT'],
            'daily-compensation' => ['VIEW', 'EDIT'],
            'commissions' => ['VIEW', 'EDIT'],
            'cash-advances' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'payroll' => ['VIEW', 'CREATE', 'APPROVE', 'MARK_PAID', 'APPROVE_REVERSAL'],
            'payslips' => ['VIEW'],
            'leave' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'profit-sharing' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE', 'MARK_PAID'],
            'ar' => ['VIEW'],
            'ap' => ['VIEW', 'PAY'],
            'collections' => ['VIEW'],
            'expenses' => ['VIEW', 'CREATE', 'EDIT', 'POST', 'MARK_PAID', 'REVERSE', 'APPROVE_REVERSAL'],
            'petty-cash' => ['VIEW', 'CREATE', 'REVERSE', 'APPROVE_REVERSAL'],
            'general-ledger' => ['VIEW'],
            'trial-balance' => ['VIEW'],
            'bank-reconciliation' => ['VIEW', 'CREATE', 'EDIT', 'RECONCILE'],
            'cash-management' => ['VIEW', 'CREATE', 'REVERSE', 'APPROVE_REVERSAL'],
            'cash-deposits' => ['VIEW', 'CREATE', 'REVERSE', 'APPROVE_REVERSAL'],
            'daily-closing' => ['VIEW', 'CLOSE', 'REOPEN'],
            'cash-flow' => ['VIEW'],
            'financial-statements' => ['VIEW'],
            'inventory-dashboard' => ['VIEW'],
            'inventory-master' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'supplier-master' => ['VIEW', 'CREATE', 'EDIT'],
            'stock-received' => ['VIEW', 'CREATE', 'REQUEST_CORRECTION', 'APPROVE_CORRECTION', 'REQUEST_REVERSAL', 'APPROVE_REVERSAL', 'RECEIVE_REFUND'],
            'stock-returns' => ['VIEW', 'CREATE', 'APPROVE', 'RECEIVE_REFUND'],
            'inventory-movements' => ['VIEW'],
            'service-consumption' => ['VIEW', 'CREATE', 'EDIT'],
            'inventory-count' => ['VIEW', 'CREATE', 'EDIT', 'APPROVE'],
            'inventory-adjustments' => ['VIEW', 'CREATE', 'APPROVE', 'ADJUST', 'REVERSE'],
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
            'discount-rules' => ['VIEW', 'CREATE', 'EDIT', 'TOGGLE'],
            'incentive-rules' => ['VIEW', 'CREATE', 'EDIT', 'TOGGLE'],
            'cash-accounts' => ['VIEW'],
            'coa' => ['VIEW', 'CREATE', 'EDIT', 'TOGGLE'],
            'accounting-mapping' => ['VIEW', 'EDIT'],
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
        $moduleName = $module === 'cash-deposits' ? 'Cash Handling' : str($module)->replace('-', ' ')->title()->toString();
        $actionName = str($action)->replace('_', ' ')->title()->toString();

        return $moduleName.' - '.$actionName;
    }
}
