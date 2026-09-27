<?php
namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class PayrollPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $myPayroll = Permission::query()->firstOrCreate(
            ['module'=>'my-payroll','action'=>'VIEW'],
            ['code'=>'my-payroll.VIEW','name'=>'View My Payroll']
        );
        $barber = Role::query()->where('code','ROLE-BAR')->first();
        if ($barber) {
            $barber->permissions()->syncWithoutDetaching([$myPayroll->id]);
            $payslipIds = Permission::query()->where('module','payslips')->pluck('id')->all();
            if ($payslipIds) $barber->permissions()->detach($payslipIds);
        }
        $manager = Role::query()->where('code','ROLE-MGR')->first();
        if ($manager) $manager->permissions()->syncWithoutDetaching([$myPayroll->id]);
    }
}
