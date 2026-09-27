<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $module = 'bookings';

        DB::table('permissions')->updateOrInsert(
            ['module' => $module, 'action' => 'DOWNLOAD'],
            [
                'code' => $module.'.DOWNLOAD',
                'name' => 'Bookings - Download',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $downloadId = DB::table('permissions')
            ->where('module', $module)
            ->where('action', 'DOWNLOAD')
            ->value('id');
        $viewId = DB::table('permissions')
            ->where('module', $module)
            ->where('action', 'VIEW')
            ->value('id');

        if (! $downloadId) return;

        $roleIds = collect();
        if ($viewId) {
            $roleIds = DB::table('role_permissions')
                ->where('permission_id', $viewId)
                ->pluck('role_id');
        }

        $roleIds = $roleIds
            ->merge(DB::table('roles')->where('is_super_admin', 1)->pluck('id'))
            ->unique();

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $downloadId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')
            ->where('module', 'bookings')
            ->where('action', 'DOWNLOAD')
            ->value('id');

        if (! $id) return;

        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();
    }
};
