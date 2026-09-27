<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'payment_splits')) $table->json('payment_splits')->nullable()->after('payment_reference');
        });
        Schema::table('inventory_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_receipts', 'payment_splits')) $table->json('payment_splits')->nullable()->after('payment_reference');
        });
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('chart_of_accounts', 'financial_statement_group')) $table->string('financial_statement_group', 80)->nullable()->after('account_group');
        });

        $defaults = [
            ['GROSS_SALES','Gross Sales',10], ['DISCOUNTS_RETURNS','Discounts & Returns',20],
            ['MATERIALS_COST','Materials Cost',30], ['COMMISSIONS','Commission',40], ['DIFFERENTIAL_PAY','Differential Pay',50],
            ['SALARIES_WAGES','Salaries and Wages',60], ['EMPLOYEE_BENEFITS','Other Employee Benefits',70],
            ['MARKETING_ADVERTISING','Marketing & Advertising',80], ['GENERAL_ADMIN','General & Admin',90],
            ['RESEARCH_DEVELOPMENT','Research & Development',100], ['OPERATING_EXPENSE','Operating Expense',110], ['OTHER','Other',999],
        ];
        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $groupId = DB::table('reference_groups')->where('business_id',$businessId)->where('code','FINANCIAL_STATEMENT_GROUP')->value('id');
            if (! $groupId) {
                $groupId = DB::table('reference_groups')->insertGetId([
                    'business_id'=>$businessId,'code'=>'FINANCIAL_STATEMENT_GROUP','name'=>'Financial Statement Group',
                    'description'=>'Custom grouping used to present accounts in the Income Statement.','is_system'=>true,'active'=>true,
                    'created_at'=>now(),'updated_at'=>now(),
                ]);
            }
            foreach ($defaults as [$code,$label,$sort]) {
                DB::table('reference_values')->updateOrInsert(
                    ['reference_group_id'=>$groupId,'code'=>$code],
                    ['display_value'=>$label,'sort_order'=>$sort,'is_system'=>false,'active'=>true,'created_at'=>now(),'updated_at'=>now()]
                );
            }


            $fsGroups = [
                'GROSS_SALES' => ['4001','4101','4102','4201','4202','4901'],
                'DISCOUNTS_RETURNS' => ['4002','4003'],
                'MATERIALS_COST' => ['5001','5002','5003','5004','5201'],
                'COMMISSIONS' => ['5101','5102'],
                'SALARIES_WAGES' => ['6001','6002','6003'],
                'EMPLOYEE_BENEFITS' => ['6004','6005'],
                'OPERATING_EXPENSE' => ['6101','6102','6103','6104'],
                'MARKETING_ADVERTISING' => ['6201','6202','6203','6204','6205'],
                'GENERAL_ADMIN' => ['6301','6302','6303','6304','6305','6306','6307','6308','6312','6313'],
                'RESEARCH_DEVELOPMENT' => ['6309','6310','6311'],
            ];
            foreach ($fsGroups as $groupCode => $accountCodes) {
                DB::table('chart_of_accounts')->where('business_id',$businessId)->whereIn('code',$accountCodes)
                    ->whereNull('financial_statement_group')->update(['financial_statement_group'=>$groupCode,'updated_at'=>now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) { if (Schema::hasColumn('chart_of_accounts','financial_statement_group')) $table->dropColumn('financial_statement_group'); });
        Schema::table('inventory_receipts', function (Blueprint $table) { if (Schema::hasColumn('inventory_receipts','payment_splits')) $table->dropColumn('payment_splits'); });
        Schema::table('expenses', function (Blueprint $table) { if (Schema::hasColumn('expenses','payment_splits')) $table->dropColumn('payment_splits'); });
    }
};
