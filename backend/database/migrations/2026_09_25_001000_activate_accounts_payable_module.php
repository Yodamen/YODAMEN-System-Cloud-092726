<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['ap', 'VIEW'],
            ['ap', 'PAY'],
        ];

        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $module.'.'.$action],
                [
                    'module' => $module,
                    'action' => $action,
                    'name' => 'Accounts Payable - '.str($action)->replace('_', ' ')->title(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['ap.VIEW', 'ap.PAY'])
            ->pluck('id');

        $roleIds = DB::table('roles')
            ->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')->whereIn('code', ['ap.VIEW', 'ap.PAY'])->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }
    }
};
