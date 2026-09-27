<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AttendancePermissionSeeder extends Seeder
{
    public function run(): void
    {
        $barber = Role::query()->where('code', 'ROLE-BAR')->first();

        if ($barber) {
            $myAttendanceView = Permission::query()
                ->where('module', 'my-attendance')
                ->where('action', 'VIEW')
                ->first();

            if ($myAttendanceView) {
                $barber->permissions()->syncWithoutDetaching([$myAttendanceView->id]);
            }

            $adminAttendancePermissionIds = Permission::query()
                ->where('module', 'attendance')
                ->pluck('id')
                ->all();

            if ($adminAttendancePermissionIds) {
                $barber->permissions()->detach($adminAttendancePermissionIds);
            }
        }
    }
}
