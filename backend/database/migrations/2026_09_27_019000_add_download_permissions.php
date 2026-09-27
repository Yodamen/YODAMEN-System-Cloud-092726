<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $modules = [
            'customers','service-balances','sales','retail-pos','sales-dashboard','collections',
            'cash-deposits','expenses','petty-cash','attendance','daily-compensation','commissions',
            'cash-advances','payroll','payslips','inventory-master','stock-received','stock-returns',
            'service-consumption','inventory-count','inventory-adjustments','coa','general-ledger',
            'trial-balance','cash-management','cash-flow','financial-statements','ar','ap',
        ];

        foreach ($modules as $module) {
            DB::table('permissions')->updateOrInsert(
                ['module' => $module, 'action' => 'DOWNLOAD'],
                [
                    'code' => $module.'.DOWNLOAD',
                    'name' => str($module)->replace('-', ' ')->title().' - Download',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );

            $downloadId = DB::table('permissions')->where('module', $module)->where('action', 'DOWNLOAD')->value('id');
            $viewId = DB::table('permissions')->where('module', $module)->where('action', 'VIEW')->value('id');
            if (! $downloadId) continue;

            $roleIds = collect();
            if ($viewId) {
                $roleIds = DB::table('role_permissions')->where('permission_id', $viewId)->pluck('role_id');
            }
            $roleIds = $roleIds->merge(DB::table('roles')->where('is_super_admin', 1)->pluck('id'))->unique();

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $downloadId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        $modules = [
            'customers','service-balances','sales','retail-pos','sales-dashboard','collections',
            'cash-deposits','expenses','petty-cash','attendance','daily-compensation','commissions',
            'cash-advances','payroll','payslips','inventory-master','stock-received','stock-returns',
            'service-consumption','inventory-count','inventory-adjustments','coa','general-ledger',
            'trial-balance','cash-management','cash-flow','financial-statements','ar','ap',
        ];

        $ids = DB::table('permissions')->where('action', 'DOWNLOAD')->whereIn('module', $modules)->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
