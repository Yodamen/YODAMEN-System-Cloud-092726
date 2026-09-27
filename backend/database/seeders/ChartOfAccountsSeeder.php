<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\ChartOfAccount;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Business::query()->get() as $business) {
            foreach ($this->accounts() as $account) {
                ChartOfAccount::query()->firstOrCreate(
                    ['business_id' => $business->id, 'code' => $account['code']],
                    ['business_id' => $business->id, ...$account]
                );
            }
        }
    }

    private function accounts(): array
    {
        $rows = [
            ['1001','Cash on Hand','ASSET','Current Asset','DEBIT','OPERATING',false,true,'CASH_ON_HAND','POSTING'],
            ['1002','Cash in Bank','ASSET','Current Asset','DEBIT','OPERATING',false,true,'CASH_IN_BANK','POSTING'],
            ['1003','E-Wallets / POS Clearing','ASSET','Current Asset','DEBIT','OPERATING',false,true,'POS_CLEARING','POSTING'],
            ['1004','Petty Cash','ASSET','Current Asset','DEBIT','OPERATING',false,true,'PETTY_CASH','POSTING'],
            ['1005','Cash with Manager / Undeposited Cash','ASSET','Current Asset','DEBIT','OPERATING',false,true,'CASH_WITH_MANAGER','POSTING'],
            ['1101','Accounts Receivable – Corporate','ASSET','Current Asset','DEBIT','OPERATING',false,false,'AR_CORPORATE','POSTING'],
            ['1102','Staff Cash Advances','ASSET','Current Asset','DEBIT','OPERATING',false,false,'STAFF_CASH_ADVANCES','POSTING'],
            ['1103','Accounts Receivable – Customers','ASSET','Current Asset','DEBIT','OPERATING',false,false,'AR_CUSTOMERS','POSTING'],
            ['1104','Supplier Refunds Receivable','ASSET','Current Asset','DEBIT','OPERATING',false,false,'SUPPLIER_REFUNDS_RECEIVABLE','POSTING'],
            ['1201','Treatment Chemicals Inventory','ASSET','Current Asset','DEBIT','OPERATING',false,false,'INVENTORY_TREATMENT_CHEMICALS','POSTING'],
            ['1202','Barber Consumables Inventory','ASSET','Current Asset','DEBIT','OPERATING',false,false,'INVENTORY_BARBER_CONSUMABLES','POSTING'],
            ['1203','Retail Products Inventory','ASSET','Current Asset','DEBIT','OPERATING',false,false,'INVENTORY_RETAIL_PRODUCTS','POSTING'],
            ['1301','Prepaid Rent','ASSET','Current Asset','DEBIT','OPERATING',false,false,null,'POSTING'],
            ['1302','Prepaid Insurance','ASSET','Current Asset','DEBIT','OPERATING',false,false,null,'POSTING'],
            ['1303','Prepaid Licenses & Permits','ASSET','Current Asset','DEBIT','OPERATING',false,false,null,'POSTING'],
            ['1501','Barber Chairs & Workstations','ASSET','Non-Current Asset','DEBIT','INVESTING',false,false,null,'POSTING'],
            ['1502','Treatment Equipment & Machines','ASSET','Non-Current Asset','DEBIT','INVESTING',false,false,null,'POSTING'],
            ['1503','Tools & Small Equipment','ASSET','Non-Current Asset','DEBIT','INVESTING',false,false,null,'POSTING'],
            ['1504','Leasehold Improvements','ASSET','Non-Current Asset','DEBIT','INVESTING',false,false,null,'POSTING'],
            ['1505','Rental Security Deposit','ASSET','Non-Current Asset','DEBIT','INVESTING',false,false,null,'POSTING'],
            ['1599','Accumulated Depreciation','ASSET','Contra Asset','CREDIT','NON_CASH',false,false,'ACCUMULATED_DEPRECIATION','POSTING'],

            ['2001','Accounts Payable – Suppliers','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'ACCOUNTS_PAYABLE','POSTING'],
            ['2002','Accrued Salaries & Wages','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'ACCRUED_SALARIES','POSTING'],
            ['2003','Accrued Commissions','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'ACCRUED_COMMISSIONS','POSTING'],
            ['2004','Accrued Rent & Utilities','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'ACCRUED_RENT_UTILITIES','POSTING'],
            ['2005','Taxes Payable','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'TAXES_PAYABLE','POSTING'],
            ['2006','Staff Tips Payable','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'STAFF_TIPS_PAYABLE','POSTING'],
            ['2007','Customer Advances / Unapplied Receipts','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'CUSTOMER_ADVANCES','POSTING'],
            ['2008','Deferred Package Revenue','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'DEFERRED_PACKAGE_REVENUE','POSTING'],
            ['2009','Deferred Membership Revenue','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'DEFERRED_MEMBERSHIP_REVENUE','POSTING'],
            ['2011','Profit Sharing Payable','LIABILITY','Current Liability','CREDIT','FINANCING',false,false,'PROFIT_SHARING_PAYABLE','POSTING'],
            ['2012','SSS Contributions Payable','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'SSS_PAYABLE','POSTING'],
            ['2013','PhilHealth Contributions Payable','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'PHILHEALTH_PAYABLE','POSTING'],
            ['2014','Pag-IBIG Contributions Payable','LIABILITY','Current Liability','CREDIT','OPERATING',false,false,'PAGIBIG_PAYABLE','POSTING'],
            ['2501','Loans Payable – Bank','LIABILITY','Long-Term Liability','CREDIT','FINANCING',false,false,'LOANS_PAYABLE_BANK','POSTING'],
            ['2502','Loans Payable – Owner','LIABILITY','Long-Term Liability','CREDIT','FINANCING',false,false,'LOANS_PAYABLE_OWNER','POSTING'],

            ['3001','Owner’s Capital','EQUITY','Capital','CREDIT','FINANCING',false,false,'OWNERS_CAPITAL','POSTING'],
            ['3002','Owner’s Drawings','EQUITY','Drawings','DEBIT','FINANCING',false,false,'OWNERS_DRAWINGS','POSTING'],
            ['3003','Partner Profit Distributions','EQUITY','Drawings','DEBIT','FINANCING',false,false,'PARTNER_PROFIT_DISTRIBUTIONS','POSTING'],
            ['3101','Retained Earnings','EQUITY','Retained Earnings','CREDIT','FINANCING',false,false,'RETAINED_EARNINGS','POSTING'],
            ['3201','Current Year Net Income','EQUITY','Net Income','CREDIT','NON_CASH',false,false,'CURRENT_YEAR_NET_INCOME','SYSTEM'],
            ['3999','Opening Balance Equity','EQUITY','Opening Balance','CREDIT','NON_CASH',false,false,'OPENING_BALANCE_EQUITY','POSTING'],

            ['4001','Service Revenue','INCOME','Service Revenue','CREDIT','OPERATING',false,false,'SERVICE_REVENUE','POSTING'],
            ['4002','Sales Discounts & Allowances','INCOME','Contra Revenue','DEBIT','OPERATING',false,false,'SALES_DISCOUNTS','POSTING'],
            ['4003','Sales Returns & Refunds','INCOME','Contra Revenue','DEBIT','OPERATING',false,false,'SALES_RETURNS','POSTING'],
            ['4101','Membership Revenue','INCOME','Memberships','CREDIT','OPERATING',false,false,'MEMBERSHIP_REVENUE','POSTING'],
            ['4102','Package Revenue','INCOME','Packages','CREDIT','OPERATING',false,false,'PACKAGE_REVENUE','POSTING'],
            ['4201','Retail Product Sales','INCOME','Retail Revenue','CREDIT','OPERATING',false,false,'RETAIL_PRODUCT_SALES','POSTING'],
            ['4202','Corporate Services Revenue','INCOME','Other Income','CREDIT','OPERATING',false,false,'CORPORATE_SERVICES_REVENUE','POSTING'],
            ['4901','Inventory Count Gain','INCOME','Inventory Adjustments','CREDIT','NON_CASH',false,false,'INVENTORY_COUNT_GAIN','POSTING'],

            ['5001','Chemicals Used – Services','COST_OF_SALES','Direct Cost','DEBIT','OPERATING',false,false,'COS_SERVICE_CHEMICALS','POSTING'],
            ['5002','Consumables Used – Services','COST_OF_SALES','Direct Cost','DEBIT','OPERATING',false,false,'COS_SERVICE_CONSUMABLES','POSTING'],
            ['5003','Inventory Freight-In / Delivery','COST_OF_SALES','Direct Cost','DEBIT','OPERATING',false,false,'INVENTORY_FREIGHT_IN','POSTING'],
            ['5004','Inventory Shrinkage / Count Variance','COST_OF_SALES','Inventory Adjustments','DEBIT','NON_CASH',false,false,'INVENTORY_COUNT_VARIANCE','POSTING'],
            ['5101','Barber Commissions','COST_OF_SALES','Direct Labor','DEBIT','OPERATING',false,false,'COS_BARBER_COMMISSIONS','POSTING'],
            ['5102','Therapist Commissions','COST_OF_SALES','Direct Labor','DEBIT','OPERATING',false,false,'COS_THERAPIST_COMMISSIONS','POSTING'],
            ['5103','Other Service Commissions','COST_OF_SALES','Direct Labor','DEBIT','OPERATING',false,false,'COS_OTHER_SERVICE_COMMISSIONS','POSTING'],
            ['5201','Retail Product Cost of Sales','COST_OF_SALES','Direct Cost','DEBIT','OPERATING',false,false,'COS_RETAIL_PRODUCTS','POSTING'],

            ['6001','Salaries – Barbers','OPERATING_EXPENSE','Payroll','DEBIT','OPERATING',true,false,'SALARY_BARBERS','POSTING'],
            ['6002','Salaries – Therapists','OPERATING_EXPENSE','Payroll','DEBIT','OPERATING',true,false,'SALARY_THERAPISTS','POSTING'],
            ['6003','Salaries – Admin','OPERATING_EXPENSE','Payroll','DEBIT','OPERATING',true,false,'SALARY_ADMIN','POSTING'],
            ['6004','Government Contributions','OPERATING_EXPENSE','Payroll','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6005','Staff Training & Certifications','OPERATING_EXPENSE','Payroll','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6006','Staff Incentives','OPERATING_EXPENSE','Payroll','DEBIT','OPERATING',true,false,'STAFF_INCENTIVES','POSTING'],
            ['6007','Employer Statutory Contributions','OPERATING_EXPENSE','Payroll','DEBIT','OPERATING',true,false,'EMPLOYER_STATUTORY_CONTRIBUTIONS','POSTING'],
            ['6101','Rent Expense','OPERATING_EXPENSE','Store Operations','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6102','Utilities Expense','OPERATING_EXPENSE','Store Operations','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6103','Laundry & Cleaning','OPERATING_EXPENSE','Store Operations','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6104','Repairs & Maintenance','OPERATING_EXPENSE','Store Operations','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6201','Advertisement Fees','OPERATING_EXPENSE','Marketing','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6202','Influencer / Partnership Fees','OPERATING_EXPENSE','Marketing','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6203','Marketing & Advertising Professional Fees','OPERATING_EXPENSE','Marketing','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6204','Offline Marketing','OPERATING_EXPENSE','Marketing','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6205','Representation Expense','OPERATING_EXPENSE','Marketing','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6206','Retail / Sales Commissions','OPERATING_EXPENSE','Marketing','DEBIT','OPERATING',true,false,'RETAIL_SALES_COMMISSIONS','POSTING'],
            ['6301','Office & Store Supplies','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6302','G&A Professional Fees','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6303','Licenses & Permits Expense','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6304','Insurance Expense','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6305','Depreciation Expense','OPERATING_EXPENSE','General & Admin','DEBIT','NON_CASH',true,false,'DEPRECIATION_EXPENSE','POSTING'],
            ['6306','Miscellaneous Expense','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6307','Taxes & Government Fees Expense','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6308','Bank & Merchant Fees','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6309','R&D Logistics Fees','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6310','Sample Products','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6311','Training & Development','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6312','Interest Expense','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,null,'POSTING'],
            ['6313','Cash Advance Write-Off Expense','OPERATING_EXPENSE','General & Admin','DEBIT','OPERATING',true,false,'CASH_ADVANCE_WRITE_OFF_EXPENSE','POSTING'],
        ];

        return array_map(function (array $row) {
            return [
                'code' => $row[0],
                'name' => $row[1],
                'account_type' => $row[2],
                'account_group' => $row[3],
                'normal_balance' => $row[4],
                'cash_flow_classification' => $row[5],
                'is_opex' => $row[6],
                'is_cash_account' => $row[7],
                'is_funding_source' => (bool) $row[7] && ! in_array($row[8], ['CASH_ON_HAND','CASH_WITH_MANAGER','POS_CLEARING'], true),
                'system_key' => $row[8],
                'posting_type' => $row[9],
                'active' => true,
            ];
        }, $rows);
    }
}
