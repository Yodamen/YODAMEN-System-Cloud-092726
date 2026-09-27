<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('module', 'sales-dashboard')
            ->where('action', 'VIEW')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereIn('code', ['ROLE-MGR', 'ROLE-REC'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        // Permission assignments are intentionally preserved on rollback so a
        // manually configured role is never stripped of access unexpectedly.
    }
};
