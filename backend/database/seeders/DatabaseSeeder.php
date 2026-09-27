<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            FoundationSeeder::class,
            MasterDataSeeder::class,
            OperationsPermissionSeeder::class,
            AttendancePermissionSeeder::class,
            HrLeaveSeeder::class,
            PayrollPermissionSeeder::class,
            HrCompletionPermissionSeeder::class,
            RetailPosPermissionSeeder::class,
            ProductMasterReferenceSeeder::class,
            InitialSuperAdminSeeder::class,
            DownloadPermissionSeeder::class,
        ]);
    }
}
