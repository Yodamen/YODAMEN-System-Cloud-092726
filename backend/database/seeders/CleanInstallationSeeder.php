<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** Configuration only: no client transactions, staff, products or default login. */
class CleanInstallationSeeder extends Seeder
{
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
            DownloadPermissionSeeder::class,
            SalesCorePermissionSeeder::class,
            ServicingPermissionSeeder::class,
            CheckoutSettlementPermissionSeeder::class,
            ChartOfAccountsSeeder::class,
        ]);
    }
}
