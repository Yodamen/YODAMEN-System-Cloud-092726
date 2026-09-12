<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class OperationsPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $reception = Role::query()->where('code', 'ROLE-REC')->first();
        if (! $reception) return;

        // Mirrors ReceptionWalkInPermission2026: Walk-In creation requires both Queue CREATE and Bookings CREATE.
        $ids = Permission::query()->whereIn('code', ['queue.CREATE', 'bookings.CREATE'])->pluck('id')->all();
        if ($ids) $reception->permissions()->syncWithoutDetaching($ids);
    }
}
