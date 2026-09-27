<?php

namespace App\Services\Accounting;

use App\Models\AccountingJournalEntry;
use App\Models\AccountingJournalLine;
use App\Models\AccountingPaymentMethodMapping;
use App\Models\AccountingProductMapping;
use App\Models\ChartOfAccount;
use App\Models\CommissionEarning;
use App\Models\EmployeeCashAdvanceLedger;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\PayrollRun;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServiceCreditLedger;
use App\Models\TransactionActionRequest;
use App\Models\TransactionRefund;
use Illuminate\Support\Collection;

class OperationalAccountingService
{
    public function __construct(private readonly AccountingJournalService $journals) {}

    public function postSaleRecognition(Sale $sale): void
    {
        if (! $sale->financial_finalized_at || strtoupper((string) $sale->status) === 'REVERSED') return;
        $sale->loadMissing(['items', 'booking']);
        $lines = [];
        $ar = 0.0;
        foreach ($sale->items as $item) {
            if (in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED','REVERSED'], true)) continue;
            $type = strtoupper((string) $item->item_type);
            if ($type === 'SERVICE_CREDIT_USE') continue;
            $net = round(max(0, (float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total)), 2);
            if ($net <= 0.004) continue;
            $ar += $net;

            if ($type === 'PACKAGE') {
                $lines[] = $this->credit('DEFERRED_PACKAGE_REVENUE', $net, $item->item_name_snapshot.' package deferred revenue');
                continue;
            }

            $gross = round(max($net, (float) ($item->line_total ?? $net)), 2);
            $discount = round(max(0, $gross - $net), 2);
            $revenueKey = $type === 'PRODUCT' ? 'RETAIL_PRODUCT_SALES' : 'SERVICE_REVENUE';
            $lines[] = $this->credit($revenueKey, $gross, $item->item_name_snapshot);
            if ($discount > 0.004) $lines[] = $this->debit('SALES_DISCOUNTS', $discount, 'Discount on '.$item->item_name_snapshot);
        }
        $ar = round($ar, 2);
        if ($ar <= 0.004) return;
        array_unshift($lines, $this->debit('AR_CUSTOMERS', $ar, 'Customer receivable recognized at financial finalization'));
        $this->journals->postEntry(
            (int) $sale->business_id, $sale->branch_id, $sale->financial_finalized_at->toDateString(),
            'SALE', (int) $sale->id, 'FINALIZE', $sale->code,
            'Financial finalization of '.$sale->code, $lines, $sale->updated_by ?: $sale->created_by,
        );
    }

    public function postPaymentReceipt(Payment $payment): void
    {
        if (strtoupper((string) $payment->status) !== 'RECEIVED' || (float) $payment->amount <= 0.004) return;
        $payment->loadMissing(['sale', 'booking']);
        $cash = $this->cashAccountForPayment($payment);
        if (! $cash) return;
        $amount = round((float) $payment->amount, 2);
        $branchId = $payment->sale?->branch_id ?: $payment->booking?->branch_id;
        $date = $payment->payment_date?->toDateString() ?: now()->toDateString();
        $this->journals->postEntry(
            (int) $payment->business_id, $branchId, $date, 'PAYMENT', (int) $payment->id, 'RECEIVE', $payment->code,
            'Payment received '.$payment->code,
            [
                ['account_id'=>$cash->id,'debit'=>$amount,'credit'=>0,'memo'=>$payment->payment_method_snapshot],
                $this->credit('CUSTOMER_ADVANCES', $amount, 'Customer cash received pending/application to receivable'),
            ],
            $payment->created_by,
        );
    }

    public function reversePaymentReceipt(Payment $payment): void
    {
        if (strtoupper((string) $payment->status) !== 'REVERSED' || (float) $payment->amount <= 0.004) return;
        $payment->loadMissing(['sale','booking']);
        $cash = $this->cashAccountForPayment($payment);
        if (! $cash) return;
        $amount = round((float) $payment->amount, 2);
        $branchId = $payment->sale?->branch_id ?: $payment->booking?->branch_id;
        $date = $payment->reversed_at?->toDateString() ?: now()->toDateString();
        $this->journals->postEntry(
            (int) $payment->business_id, $branchId, $date, 'PAYMENT', (int) $payment->id, 'REVERSE_RECEIPT', $payment->code,
            'Reverse payment receipt '.$payment->code,
            [
                $this->debit('CUSTOMER_ADVANCES', $amount, 'Reverse customer receipt liability'),
                ['account_id'=>$cash->id,'debit'=>0,'credit'=>$amount,'memo'=>'Reverse '.$payment->payment_method_snapshot],
            ],
            $payment->reversed_by ?: $payment->created_by,
        );
    }

    public function postPaymentApplication(PaymentApplication $application, bool $reverse = false): void
    {
        $type = strtoupper((string) $application->target_type);
        if ($type === 'ADVANCE_RESERVE') return;
        if (! $reverse && strtoupper((string) $application->status) !== 'ACTIVE') return;
        if ($reverse && strtoupper((string) $application->status) !== 'REVERSED') return;
        $amount = round((float) $application->amount, 2);
        if ($amount <= 0.004) return;
        $application->loadMissing(['payment', 'sale', 'sourceSale', 'targetSaleItem.sale']);
        $payment = $application->payment;
        if (! $payment) return;
        $targetSale = $application->targetSaleItem?->sale ?: $application->sourceSale ?: $application->sale;
        $branchId = $targetSale?->branch_id ?: $payment->sale?->branch_id ?: $payment->booking?->branch_id;
        $date = $reverse
            ? ($application->reversed_at?->toDateString() ?: now()->toDateString())
            : ($payment->payment_date?->toDateString() ?: now()->toDateString());
        if ($type === 'STAFF_TIP') {
            $lines = $reverse
                ? [$this->debit('STAFF_TIPS_PAYABLE', $amount, 'Reverse staff tip liability'), $this->credit('CUSTOMER_ADVANCES', $amount, 'Restore unapplied customer receipt')]
                : [$this->debit('CUSTOMER_ADVANCES', $amount, 'Apply electronic tip receipt'), $this->credit('STAFF_TIPS_PAYABLE', $amount, 'Staff tip payable')];
        } else {
            $lines = $reverse
                ? [$this->debit('AR_CUSTOMERS', $amount, 'Restore receivable from reversed payment allocation'), $this->credit('CUSTOMER_ADVANCES', $amount, 'Restore unapplied customer receipt')]
                : [$this->debit('CUSTOMER_ADVANCES', $amount, 'Apply customer receipt'), $this->credit('AR_CUSTOMERS', $amount, 'Settle customer receivable')];
        }
        $this->journals->postEntry(
            (int) $payment->business_id, $branchId, $date, 'PAYMENT_APPLICATION', (int) $application->id,
            $reverse ? 'REVERSE' : 'APPLY', $application->code,
            ($reverse ? 'Reverse' : 'Apply').' payment allocation '.$application->code,
            $lines, $reverse ? $application->reversed_by : $application->created_by,
        );
    }

    public function postInventoryReceipt(InventoryReceipt $receipt): void
    {
        if (strtoupper((string) $receipt->status) !== 'POSTED') return;

        $receipt->loadMissing(['lines.product']);
        if ($receipt->lines->isEmpty()) return;

        // Receipts posted before this receipt-level integration may already have one
        // journal per STOCK_RECEIVED movement. Preserve those historical journals and
        // do not add a second receipt-level entry on top of them.
        $legacyMovementIds = InventoryMovement::query()
            ->where('business_id', $receipt->business_id)
            ->where('movement_type', 'STOCK_RECEIVED')
            ->where('reference_code', $receipt->code)
            ->pluck('id');
        if ($legacyMovementIds->isNotEmpty() && AccountingJournalEntry::query()
            ->where('business_id', $receipt->business_id)
            ->where('source_type', 'INVENTORY_MOVEMENT')
            ->whereIn('source_id', $legacyMovementIds)
            ->where('source_event', 'POST')
            ->exists()) {
            return;
        }

        $inventoryDebits = [];
        foreach ($receipt->lines as $line) {
            $amount = round((float) $line->line_total, 2);
            if ($amount <= 0.004) continue;

            $mapping = $this->ensureProductMapping((int) $receipt->business_id, (int) $line->retail_product_id);
            if (! $mapping) return;

            $accountId = (int) $mapping->inventory_account_id;
            $inventoryDebits[$accountId] = round(($inventoryDebits[$accountId] ?? 0) + $amount, 2);
        }

        $deliveryFee = round(max(0, (float) ($receipt->delivery_fee ?? 0)), 2);
        $grandTotal = round(array_sum($inventoryDebits) + $deliveryFee, 2);
        if ($grandTotal <= 0.004) return;

        $lines = [];
        foreach ($inventoryDebits as $accountId => $amount) {
            $lines[] = [
                'account_id' => $accountId,
                'debit' => $amount,
                'credit' => 0,
                'memo' => 'Inventory received '.$receipt->code,
            ];
        }
        if ($deliveryFee > 0.004) {
            $lines[] = $this->debit('INVENTORY_FREIGHT_IN', $deliveryFee, 'Stock receipt delivery / freight-in');
        }

        $paymentStatus = strtoupper((string) ($receipt->payment_status ?: 'UNPAID'));
        $splits = $receipt->payment_splits ?: [];
        if (! $splits && $paymentStatus === 'PAID' && $receipt->payment_method_reference_id) {
            $mapping = AccountingPaymentMethodMapping::query()->where('business_id',$receipt->business_id)->where('payment_method_reference_id',$receipt->payment_method_reference_id)->with('cashAccount')->first();
            if ($mapping?->cashAccount) $splits = [[
                'funding_account_id'=>$mapping->cash_account_id,
                'cash_account_id'=>$mapping->cash_account_id,
                'amount'=>$grandTotal,
                'payment_method'=>$receipt->payment_method_snapshot ?: 'Payment',
            ]];
        }

        $paidAmount = 0.0;
        foreach ($splits as $split) {
            $amount = round((float) ($split['amount'] ?? 0), 2);
            if ($amount <= 0.004) continue;
            $accountId = (int) ($split['funding_account_id'] ?? $split['cash_account_id'] ?? 0);
            $funding = ChartOfAccount::query()
                ->where('business_id', $receipt->business_id)
                ->whereKey($accountId)
                ->where('active', true)
                ->where('posting_type', 'POSTING')
                ->where('is_funding_source', true)
                ->first();
            if (! $funding) return;
            $paidAmount = round($paidAmount + $amount, 2);
            $lines[] = [
                'account_id'=>$funding->id,
                'debit'=>0,
                'credit'=>$amount,
                'memo'=>'Inventory receipt funded via '.($split['funding_source'] ?? $split['payment_method'] ?? $funding->name),
            ];
        }

        if ($paidAmount - $grandTotal > 0.01) return;
        $apBalance = round(max(0, $grandTotal - $paidAmount), 2);
        if ($paymentStatus === 'PAID' && $apBalance > 0.01) return;
        if ($apBalance > 0.004) {
            $lines[] = $this->credit('ACCOUNTS_PAYABLE', $apBalance, 'Supplier payable balance from inventory receipt');
        }

        $this->journals->postEntry(
            (int) $receipt->business_id,
            $receipt->branch_id,
            $receipt->received_date?->toDateString() ?: now()->toDateString(),
            'INVENTORY_RECEIPT',
            (int) $receipt->id,
            'POST',
            $receipt->code,
            'Stock receipt '.$receipt->code,
            $lines,
            $receipt->created_by,
        );
    }

    public function postInventoryMovement(InventoryMovement $movement): void
    {
        $qty = round((float) $movement->quantity_change, 4);
        $cost = round(abs($qty) * (float) $movement->unit_cost_snapshot, 2);
        if ($cost <= 0.004) return;
        $mapping = $this->ensureProductMapping((int) $movement->business_id, (int) $movement->retail_product_id);
        if (! $mapping) return;
        $type = strtoupper((string) $movement->movement_type);
        if ($type === 'STOCK_RECEIVED') return; // Stock receipts post once at the receipt level so payment method and delivery fee stay balanced.

        $date = $movement->movement_at?->toDateString() ?: now()->toDateString();
        $lines = [];

        if ($type === 'RETAIL_SALE') {
            $lines = [
                ['account_id'=>$mapping->retail_cost_account_id,'debit'=>$cost,'credit'=>0,'memo'=>'Retail product cost of sales'],
                ['account_id'=>$mapping->inventory_account_id,'debit'=>0,'credit'=>$cost,'memo'=>'Retail inventory issued'],
            ];
        } elseif ($type === 'SERVICE_CONSUMPTION') {
            $lines = [
                ['account_id'=>$mapping->service_cost_account_id,'debit'=>$cost,'credit'=>0,'memo'=>'Service material cost'],
                ['account_id'=>$mapping->inventory_account_id,'debit'=>0,'credit'=>$cost,'memo'=>'Material inventory consumed'],
            ];
        } elseif (in_array($type, ['TRANSACTION_REVERSAL','TRANSACTION_CORRECTION','SERVICE_CONSUMPTION_REVERSAL','SERVICE_CONSUMPTION_CORRECTION','REFUND_RETURN','RESTORE','CANCEL'], true)) {
            $costAccountId = ($movement->source_service_consumption_line_id || str_contains($type, 'SERVICE_CONSUMPTION'))
                ? $mapping->service_cost_account_id
                : $mapping->retail_cost_account_id;
            $lines = $qty >= 0
                ? [
                    ['account_id'=>$mapping->inventory_account_id,'debit'=>$cost,'credit'=>0,'memo'=>'Inventory restored by transaction action'],
                    ['account_id'=>$costAccountId,'debit'=>0,'credit'=>$cost,'memo'=>'Reverse historical cost of sales'],
                ]
                : [
                    ['account_id'=>$costAccountId,'debit'=>$cost,'credit'=>0,'memo'=>'Additional cost from transaction correction'],
                    ['account_id'=>$mapping->inventory_account_id,'debit'=>0,'credit'=>$cost,'memo'=>'Additional inventory issued'],
                ];
        } elseif (in_array($type, ['INVENTORY_COUNT_ADJUSTMENT','ADJUSTMENT'], true)) {
            $lines = $qty >= 0
                ? [
                    ['account_id'=>$mapping->inventory_account_id,'debit'=>$cost,'credit'=>0,'memo'=>'Approved inventory surplus'],
                    $this->credit('INVENTORY_COUNT_GAIN', $cost, 'Approved inventory surplus'),
                ]
                : [
                    $this->debit('INVENTORY_COUNT_VARIANCE', $cost, 'Approved inventory shortage'),
                    ['account_id'=>$mapping->inventory_account_id,'debit'=>0,'credit'=>$cost,'memo'=>'Approved inventory shortage'],
                ];
        } elseif ($type === 'ADJUSTMENT_REVERSAL') {
            $source = $movement->source_inventory_movement_id
                ? InventoryMovement::query()->find($movement->source_inventory_movement_id)
                : null;
            if (! $source) return;
            $sourceQty = round((float) $source->quantity_change, 4);
            $lines = $sourceQty >= 0
                ? [
                    $this->debit('INVENTORY_COUNT_GAIN', $cost, 'Reverse prior inventory surplus'),
                    ['account_id'=>$mapping->inventory_account_id,'debit'=>0,'credit'=>$cost,'memo'=>'Reverse prior inventory surplus'],
                ]
                : [
                    ['account_id'=>$mapping->inventory_account_id,'debit'=>$cost,'credit'=>0,'memo'=>'Restore prior inventory shortage'],
                    $this->credit('INVENTORY_COUNT_VARIANCE', $cost, 'Reverse prior inventory shortage'),
                ];
        } elseif (in_array($type, ['STOCK_RECEIPT_REVERSAL','STOCK_RECEIPT_CORRECTION','STOCK_RETURN'], true)) {
            return; // These movements are posted through consolidated receipt/return journals.
        }

        if (! $lines) return;
        $this->journals->postEntry(
            (int) $movement->business_id, $movement->branch_id, $date, 'INVENTORY_MOVEMENT', (int) $movement->id, 'POST',
            $movement->reference_code, $movement->reason ?: $type, $lines, $movement->created_by,
        );
    }

    public function postCommission(CommissionEarning $earning): void
    {
        $amount = round((float) $earning->commission_amount, 2);
        if (abs($amount) <= 0.004) return;
        $expense = $this->commissionExpenseAccount($earning);
        if (! $expense) return;
        $isIncentive = strtoupper((string) $earning->earning_type) === 'INCENTIVE';
        $label = $isIncentive ? 'Incentive' : 'Commission';
        $lines = $amount > 0
            ? [['account_id'=>$expense->id,'debit'=>$amount,'credit'=>0,'memo'=>$label.' earned'], $this->credit('ACCRUED_COMMISSIONS', $amount, $label.' payable')]
            : [$this->debit('ACCRUED_COMMISSIONS', abs($amount), $label.' reversal/adjustment'), ['account_id'=>$expense->id,'debit'=>0,'credit'=>abs($amount),'memo'=>'Reverse '.strtolower($label).' expense']];
        $this->journals->postEntry(
            (int) $earning->business_id, $earning->sale?->branch_id, $earning->work_date?->toDateString() ?: now()->toDateString(),
            'COMMISSION_EARNING', (int) $earning->id, 'ACCRUE', $earning->code,
            ($amount > 0 ? $label.' accrual ' : $label.' adjustment ').$earning->code, $lines, $earning->created_by,
        );
    }

    public function reverseCommission(CommissionEarning $earning): void
    {
        if (strtoupper((string) $earning->status) !== 'REVERSED' || (float) $earning->commission_amount <= 0.004) return;
        if (CommissionEarning::query()->where('source_commission_earning_id', $earning->id)->where('commission_amount','<',0)->exists()) return;
        $expense = $this->commissionExpenseAccount($earning);
        if (! $expense) return;
        $amount = round((float) $earning->commission_amount, 2);
        $this->journals->postEntry(
            (int) $earning->business_id, $earning->sale?->branch_id, $earning->reversed_at?->toDateString() ?: now()->toDateString(),
            'COMMISSION_EARNING', (int) $earning->id, 'REVERSE', $earning->code,
            'Reverse commission '.$earning->code,
            [$this->debit('ACCRUED_COMMISSIONS', $amount, 'Reverse commission payable'), ['account_id'=>$expense->id,'debit'=>0,'credit'=>$amount,'memo'=>'Reverse commission expense']],
            $earning->reversed_by ?: $earning->created_by,
        );
    }

    public function postPayrollApproval(PayrollRun $run): void
    {
        if (strtoupper((string) $run->status) !== 'APPROVED') return;
        $run->loadMissing('lines');
        $lines = [];
        foreach ($run->lines as $line) {
            $nonCommission = round((float) $line->gross_compensation - (float) $line->commission_amount + (float) $line->adjustments_total, 2);
            $salary = $this->salaryAccount((int) $run->business_id, (string) $line->position_snapshot);
            if ($salary && abs($nonCommission) > 0.004) {
                if ($nonCommission > 0) {
                    $lines[] = ['account_id'=>$salary->id,'debit'=>$nonCommission,'credit'=>0,'memo'=>$line->personnel_name_snapshot.' compensation'];
                    $lines[] = $this->credit('ACCRUED_SALARIES', $nonCommission, $line->personnel_name_snapshot.' salary payable');
                } else {
                    $lines[] = $this->debit('ACCRUED_SALARIES', abs($nonCommission), 'Negative payroll adjustment');
                    $lines[] = ['account_id'=>$salary->id,'debit'=>0,'credit'=>abs($nonCommission),'memo'=>'Negative compensation adjustment'];
                }
            }
            $deduction = round((float) $line->cash_advance_deductions, 2);
            if ($deduction > 0.004) {
                $lines[] = $this->debit('ACCRUED_SALARIES', $deduction, 'Payroll cash advance deduction');
                $lines[] = $this->credit('STAFF_CASH_ADVANCES', $deduction, 'Reduce employee cash advance receivable');
            }

            $statutory = [
                ['employee' => (float) $line->sss_employee, 'employer' => (float) $line->sss_employer, 'payable' => 'SSS_PAYABLE', 'label' => 'SSS'],
                ['employee' => (float) $line->philhealth_employee, 'employer' => (float) $line->philhealth_employer, 'payable' => 'PHILHEALTH_PAYABLE', 'label' => 'PhilHealth'],
                ['employee' => (float) $line->pagibig_employee, 'employer' => (float) $line->pagibig_employer, 'payable' => 'PAGIBIG_PAYABLE', 'label' => 'Pag-IBIG'],
            ];
            foreach ($statutory as $contribution) {
                $employeeShare = round($contribution['employee'], 2);
                $employerShare = round($contribution['employer'], 2);
                if ($employeeShare > 0.004) {
                    $lines[] = $this->debit('ACCRUED_SALARIES', $employeeShare, $contribution['label'].' employee deduction');
                    $lines[] = $this->credit($contribution['payable'], $employeeShare, $contribution['label'].' employee contribution payable');
                }
                if ($employerShare > 0.004) {
                    $lines[] = $this->debit('EMPLOYER_STATUTORY_CONTRIBUTIONS', $employerShare, $contribution['label'].' employer contribution');
                    $lines[] = $this->credit($contribution['payable'], $employerShare, $contribution['label'].' employer contribution payable');
                }
            }
        }
        if (! $lines) return;
        $this->journals->postEntry(
            (int) $run->business_id, $run->branch_id, $run->pay_date?->toDateString() ?: $run->period_end?->toDateString(),
            'PAYROLL_RUN', (int) $run->id, 'APPROVE', $run->code,
            'Approved payroll '.$run->code, $lines, $run->approved_by ?: $run->created_by,
        );
    }

    public function postPayrollPayment(PayrollRun $run): void
    {
        if (strtoupper((string) $run->status) !== 'PAID') return;
        $run->loadMissing(['lines', 'paymentAccount', 'paymentSplits.fundingAccount']);
        $commission = round((float) $run->lines->sum('commission_amount'), 2);
        $salaryPayable = round((float) $run->net_pay - $commission, 2);
        $cash = round((float) $run->net_pay, 2);
        if ($cash < -0.004) return;

        $splits = $run->paymentSplits->filter(function ($split) {
            $account = $split->fundingAccount;
            return $account && $account->active && strtoupper((string) $account->posting_type) === 'POSTING' && (float) $split->amount > 0.004;
        })->values();

        if ($splits->isEmpty() && $cash > 0.004) {
            $funding = $run->paymentAccount;
            if (! $funding || ! $funding->active || strtoupper((string) $funding->posting_type) !== 'POSTING') {
                $funding = $this->journals->accountByKey((int) $run->business_id, 'CASH_IN_BANK');
            }
            if (! $funding) return;
            $splits = collect([(object) [
                'amount' => $cash,
                'reference' => $run->payment_reference,
                'fundingAccount' => $funding,
            ]]);
        }

        $lines = [];
        if ($salaryPayable > 0.004) $lines[] = $this->debit('ACCRUED_SALARIES', $salaryPayable, 'Settle salary payable');
        elseif ($salaryPayable < -0.004) $lines[] = $this->credit('ACCRUED_SALARIES', abs($salaryPayable), 'Negative salary settlement');
        if ($commission > 0.004) $lines[] = $this->debit('ACCRUED_COMMISSIONS', $commission, 'Settle commissions payable');
        elseif ($commission < -0.004) $lines[] = $this->credit('ACCRUED_COMMISSIONS', abs($commission), 'Recover prior commission through payroll');

        foreach ($splits as $split) {
            $funding = $split->fundingAccount;
            $amount = round((float) $split->amount, 2);
            if (! $funding || $amount <= 0.004) continue;
            $memo = 'Payroll disbursement via '.$funding->name;
            if (! empty($split->reference)) $memo .= ' · '.$split->reference;
            $lines[] = ['account_id'=>$funding->id,'debit'=>0,'credit'=>$amount,'memo'=>$memo];
        }

        if (! $lines) return;
        $this->journals->postEntry(
            (int) $run->business_id, $run->branch_id, $run->paid_at?->toDateString() ?: $run->pay_date?->toDateString(),
            'PAYROLL_RUN', (int) $run->id, 'PAY', $run->code,
            'Payroll payment '.$run->code, $lines, $run->paid_by ?: $run->approved_by,
        );
    }

    public function postCashAdvanceLedger(EmployeeCashAdvanceLedger $entry): void
    {
        $type = strtoupper((string) $entry->entry_type);
        $amount = round(abs((float) $entry->amount), 2);
        if ($amount <= 0.004 || in_array($type, ['PAYROLL_DEDUCTION','WRITE_OFF'], true)) return;
        $entry->loadMissing('cashAdvance.personnel');
        $advance = $entry->cashAdvance;
        if (! $advance) return;
        $lines = match ($type) {
            'ISSUE' => [$this->debit('STAFF_CASH_ADVANCES', $amount, 'Employee cash advance issued'), $this->credit('CASH_IN_BANK', $amount, 'Cash advance disbursement')],
            'REPAYMENT' => [$this->debit('CASH_ON_HAND', $amount, 'Cash advance repayment received'), $this->credit('STAFF_CASH_ADVANCES', $amount, 'Reduce employee cash advance receivable')],
            default => [],
        };
        if (! $lines) return;
        $this->journals->postEntry(
            (int) $entry->business_id, $advance->personnel?->branch_id, $entry->entry_date?->toDateString() ?: now()->toDateString(),
            'EMPLOYEE_CASH_ADVANCE_LEDGER', (int) $entry->id, $type, $advance->code,
            str($type)->replace('_',' ')->title().' '.$advance->code, $lines, $entry->created_by,
        );
    }

    public function postServiceCreditMovement(ServiceCreditLedger $movement): void
    {
        $type = strtoupper((string) $movement->movement_type);
        if (! in_array($type, ['CONSUME','RESTORE'], true) || (int) $movement->sessions <= 0) return;
        $movement->loadMissing(['packageEnrollment.openingBalance','visit','booking']);
        $enrollment = $movement->packageEnrollment;
        if (! $enrollment || (int) $enrollment->total_sessions <= 0) return;
        $total = round((float) $enrollment->package_price_snapshot, 2);
        $sessions = max(1, (int) $enrollment->total_sessions);
        $amount = round(($total / $sessions) * (int) $movement->sessions, 2);
        if ($amount <= 0.004) return;
        $branchId = $movement->visit?->branch_id ?: $movement->booking?->branch_id ?: $enrollment->originSale?->branch_id ?: $enrollment->openingBalance?->branch_id;
        $date = $movement->created_at?->toDateString() ?: now()->toDateString();
        $lines = $type === 'CONSUME'
            ? [$this->debit('DEFERRED_PACKAGE_REVENUE', $amount, 'Recognize package session'), $this->credit('PACKAGE_REVENUE', $amount, 'Package session revenue')]
            : [$this->debit('PACKAGE_REVENUE', $amount, 'Reverse package session revenue'), $this->credit('DEFERRED_PACKAGE_REVENUE', $amount, 'Restore deferred package balance')];
        $this->journals->postEntry(
            (int) $movement->business_id, $branchId, $date, 'SERVICE_CREDIT_LEDGER', (int) $movement->id, $type, $movement->code,
            ($type === 'CONSUME' ? 'Recognize' : 'Restore').' package session revenue '.$movement->code, $lines, $movement->processed_by,
        );
    }

    public function postTransactionAction(TransactionActionRequest $request): void
    {
        if (strtoupper((string) $request->status) !== 'POSTED') return;
        $sale = Sale::query()->whereKey($request->sale_id)->with('items')->first();
        if (! $sale || ! $sale->financial_finalized_at) return;
        $oldItems = collect($request->original_snapshot['items'] ?? [])->keyBy(fn ($row) => (int) ($row['id'] ?? 0));
        $newItems = $sale->items->keyBy('id');
        $ids = $oldItems->keys()->merge($newItems->keys())->unique();
        $lines = [];
        $arDelta = 0.0;
        foreach ($ids as $id) {
            $old = $oldItems->get($id, []);
            /** @var SaleItem|null $current */
            $current = $newItems->get($id);
            $type = strtoupper((string) ($current?->item_type ?? ($old['item_type'] ?? 'SERVICE')));
            if ($type === 'SERVICE_CREDIT_USE') continue;
            $oldAmount = round(max(0, (float) ($old['effective_net_line_total'] ?? $old['net_line_total'] ?? 0)), 2);
            $removed = $current && in_array(strtoupper((string) ($current->correction_status ?? 'ACTIVE')), ['REMOVED','REVERSED'], true);
            $newAmount = $current && ! $removed ? round(max(0, (float) ($current->effective_net_line_total ?? $current->net_line_total ?? 0)), 2) : 0.0;
            $delta = round($newAmount - $oldAmount, 2);
            if (abs($delta) <= 0.004) continue;
            $arDelta += $delta;
            if ($delta > 0) {
                $key = $type === 'PACKAGE' ? 'DEFERRED_PACKAGE_REVENUE' : ($type === 'PRODUCT' ? 'RETAIL_PRODUCT_SALES' : 'SERVICE_REVENUE');
                $lines[] = $this->credit($key, $delta, 'Approved transaction increase');
            } else {
                $key = $type === 'PACKAGE' ? 'DEFERRED_PACKAGE_REVENUE' : 'SALES_RETURNS';
                $lines[] = $this->debit($key, abs($delta), 'Approved transaction reduction');
            }
        }
        $arDelta = round($arDelta, 2);
        if ($arDelta > 0.004) array_unshift($lines, $this->debit('AR_CUSTOMERS', $arDelta, 'Increase customer receivable'));
        elseif ($arDelta < -0.004) $lines[] = $this->credit('AR_CUSTOMERS', abs($arDelta), 'Reduce customer receivable');
        if (! $lines) return;
        $this->journals->postEntry(
            (int) $request->business_id, $request->branch_id, $request->posted_at?->toDateString() ?: now()->toDateString(),
            'TRANSACTION_ACTION', (int) $request->id, 'POST', $request->code,
            str($request->action_type)->replace('_',' ')->title().' '.$request->code, $lines, $request->posted_by,
        );

        // A package purchase is initially deferred, while completed package sessions
        // are recognized separately through SERVICE_CREDIT_LEDGER journals. When the
        // originating package line itself is removed/reversed, the main transaction
        // action reverses the package receivable/deferred balance. Any package revenue
        // that was already recognized from that now-cancelled package must also be
        // reversed, otherwise Package Revenue remains overstated and Deferred Package
        // Revenue is over-debited. Keep this as a separate idempotent source event so
        // historical actions can be repaired safely without rewriting the original
        // transaction-action journal.
        $this->postPackageOriginRevenueReversal($request);
    }

    public function postPackageOriginRevenueReversal(TransactionActionRequest $request): void
    {
        if (strtoupper((string) $request->status) !== 'POSTED') return;
        $actionType = strtoupper((string) $request->action_type);
        if (! in_array($actionType, ['CORRECTION', 'REVERSAL'], true)) return;

        $packageEnrollmentIds = collect();

        if ($actionType === 'CORRECTION') {
            $request->loadMissing('lines');
            foreach ($request->lines as $line) {
                if (strtoupper((string) $line->line_action) !== 'REMOVE') continue;
                $snapshot = $line->original_snapshot ?? [];
                if (strtoupper((string) ($snapshot['item_type'] ?? '')) !== 'PACKAGE') continue;
                $enrollmentId = (int) ($snapshot['package_enrollment_id'] ?? 0);
                if ($enrollmentId > 0) $packageEnrollmentIds->push($enrollmentId);
            }
        } else {
            foreach (collect($request->original_snapshot['items'] ?? []) as $snapshot) {
                if (strtoupper((string) ($snapshot['item_type'] ?? '')) !== 'PACKAGE') continue;
                if (in_array(strtoupper((string) ($snapshot['correction_status'] ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true)) continue;
                if ((float) ($snapshot['effective_net_line_total'] ?? $snapshot['net_line_total'] ?? 0) <= 0.004) continue;
                $enrollmentId = (int) ($snapshot['package_enrollment_id'] ?? 0);
                if ($enrollmentId > 0) $packageEnrollmentIds->push($enrollmentId);
            }
        }

        $packageEnrollmentIds = $packageEnrollmentIds->unique()->values();
        if ($packageEnrollmentIds->isEmpty()) return;

        $ledgerIds = ServiceCreditLedger::query()
            ->whereIn('package_enrollment_id', $packageEnrollmentIds)
            ->pluck('id');
        if ($ledgerIds->isEmpty()) return;

        $recognized = AccountingJournalLine::query()
            ->where('account_key', 'PACKAGE_REVENUE')
            ->whereHas('journalEntry', function ($query) use ($ledgerIds) {
                $query->where('source_type', 'SERVICE_CREDIT_LEDGER')
                    ->whereIn('source_id', $ledgerIds)
                    ->where('status', 'POSTED');
            })
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) AS net_recognized')
            ->value('net_recognized');

        $amount = round(max(0, (float) $recognized), 2);
        if ($amount <= 0.004) return;

        $this->journals->postEntry(
            (int) $request->business_id,
            $request->branch_id,
            $request->posted_at?->toDateString() ?: now()->toDateString(),
            'TRANSACTION_ACTION',
            (int) $request->id,
            'PACKAGE_REVENUE_REVERSAL',
            $request->code,
            'Reverse recognized package revenue for cancelled originating package '.$request->code,
            [
                $this->debit('PACKAGE_REVENUE', $amount, 'Reverse package-session revenue tied to cancelled package'),
                $this->credit('DEFERRED_PACKAGE_REVENUE', $amount, 'Restore deferred package balance before package sale reversal'),
            ],
            $request->posted_by,
        );
    }

    public function postCashRefund(TransactionRefund $refund): void
    {
        if (strtoupper((string) $refund->status) !== 'POSTED') return;
        $amount = round((float) $refund->cash_refund_amount, 2);
        if ($amount <= 0.004) return;
        $refund->loadMissing('sale');
        $cash = $this->cashAccountForLabel((int) $refund->business_id, (string) $refund->refund_method);
        if (! $cash) return;
        $date = $refund->processed_at?->toDateString() ?: now()->toDateString();
        $this->journals->postEntry(
            (int) $refund->business_id, $refund->branch_id, $date, 'TRANSACTION_REFUND', (int) $refund->id, 'CASH_REFUND', $refund->code,
            'Cash refund '.$refund->code,
            [$this->debit('CUSTOMER_ADVANCES', $amount, 'Settle customer refund/unapplied receipt'), ['account_id'=>$cash->id,'debit'=>0,'credit'=>$amount,'memo'=>'Refund via '.$refund->refund_method]],
            $refund->processed_by,
        );
    }

    public function productMapping(int $businessId, int $productId): ?AccountingProductMapping
    {
        return $this->ensureProductMapping($businessId, $productId);
    }

    private function ensureProductMapping(int $businessId, int $productId): ?AccountingProductMapping
    {
        $existing = AccountingProductMapping::query()
            ->where('business_id', $businessId)
            ->where('retail_product_id', $productId)
            ->first();
        if ($existing) return $existing;

        $product = \App\Models\RetailProduct::query()
            ->where('business_id', $businessId)
            ->whereKey($productId)
            ->first();
        if (! $product) return null;

        $category = strtoupper((string) ($product->category ?? ''));
        if ($product->retail_available) {
            $inventoryKey = 'INVENTORY_RETAIL_PRODUCTS';
            $serviceCostKey = 'COS_SERVICE_CONSUMABLES';
        } elseif (str_contains($category, 'BARBER') || str_contains($category, 'CONSUMABLE')) {
            $inventoryKey = 'INVENTORY_BARBER_CONSUMABLES';
            $serviceCostKey = 'COS_SERVICE_CONSUMABLES';
        } else {
            $inventoryKey = 'INVENTORY_TREATMENT_CHEMICALS';
            $serviceCostKey = 'COS_SERVICE_CHEMICALS';
        }
        $inventory = $this->journals->accountByKey($businessId, $inventoryKey);
        $serviceCost = $this->journals->accountByKey($businessId, $serviceCostKey);
        $retailCost = $this->journals->accountByKey($businessId, 'COS_RETAIL_PRODUCTS');
        if (! $inventory || ! $serviceCost || ! $retailCost) return null;

        return AccountingProductMapping::query()->firstOrCreate(
            ['business_id' => $businessId, 'retail_product_id' => $productId],
            [
                'inventory_account_id' => $inventory->id,
                'service_cost_account_id' => $serviceCost->id,
                'retail_cost_account_id' => $retailCost->id,
            ]
        );
    }

    private function commissionExpenseAccount(CommissionEarning $earning): ?ChartOfAccount
    {
        if (strtoupper((string) $earning->earning_type) === 'INCENTIVE') return $this->journals->accountByKey((int) $earning->business_id, 'STAFF_INCENTIVES');
        if (strtoupper((string) $earning->earning_type) === 'RETAIL') return $this->journals->accountByKey((int) $earning->business_id, 'RETAIL_SALES_COMMISSIONS');
        $earning->loadMissing('personnel.position','sale');
        $position = strtoupper((string) $earning->personnel?->position?->display_value);
        $key = str_contains($position, 'BARBER') ? 'COS_BARBER_COMMISSIONS' : (str_contains($position, 'THERAPIST') ? 'COS_THERAPIST_COMMISSIONS' : 'COS_OTHER_SERVICE_COMMISSIONS');
        return $this->journals->accountByKey((int) $earning->business_id, $key);
    }

    private function salaryAccount(int $businessId, string $position): ?ChartOfAccount
    {
        $position = strtoupper($position);
        $key = str_contains($position, 'BARBER') ? 'SALARY_BARBERS' : (str_contains($position, 'THERAPIST') ? 'SALARY_THERAPISTS' : 'SALARY_ADMIN');
        return $this->journals->accountByKey($businessId, $key);
    }

    private function cashAccountForPayment(Payment $payment): ?ChartOfAccount
    {
        $mapping = null;
        if ($payment->payment_method_reference_id) {
            $mapping = AccountingPaymentMethodMapping::query()->where('business_id',$payment->business_id)->where('payment_method_reference_id',$payment->payment_method_reference_id)->first();
        }
        if ($mapping) return ChartOfAccount::query()->where('business_id',$payment->business_id)->whereKey($mapping->cash_account_id)->where('active',true)->where('is_cash_account',true)->first();
        return $this->cashAccountForLabel((int) $payment->business_id, (string) $payment->payment_method_snapshot);
    }

    private function cashAccountForLabel(int $businessId, string $label): ?ChartOfAccount
    {
        $normalized = strtoupper(trim($label));

        // Refunds currently store the payment/refund method snapshot as text.
        // Resolve that snapshot through Accounting Mapping first so a refund paid
        // through a user-created BDO/GCash account returns cash from the exact same
        // COA used for collections instead of falling back to a generic account.
        if ($normalized !== '') {
            $mappedAccountId = AccountingPaymentMethodMapping::query()
                ->where('accounting_payment_method_mappings.business_id', $businessId)
                ->join('reference_values as rv', 'rv.id', '=', 'accounting_payment_method_mappings.payment_method_reference_id')
                ->where(function ($query) use ($normalized) {
                    $query->whereRaw('UPPER(TRIM(rv.display_value)) = ?', [$normalized])
                        ->orWhereRaw('UPPER(TRIM(rv.code)) = ?', [$normalized]);
                })
                ->value('accounting_payment_method_mappings.cash_account_id');

            if ($mappedAccountId) {
                $mapped = ChartOfAccount::query()
                    ->where('business_id', $businessId)
                    ->whereKey($mappedAccountId)
                    ->where('active', true)
                    ->where('posting_type', 'POSTING')
                    ->where('is_cash_account', true)
                    ->first();
                if ($mapped) return $mapped;
            }
        }

        $key = str_contains($normalized, 'CASH')
            ? 'CASH_ON_HAND'
            : ((str_contains($normalized, 'BANK') || str_contains($normalized, 'TRANSFER')) ? 'CASH_IN_BANK' : 'POS_CLEARING');

        return $this->journals->accountByKey($businessId, $key);
    }

    private function debit(string $accountKey, float $amount, ?string $memo = null): array
    {
        return ['account_key'=>$accountKey,'debit'=>round($amount,2),'credit'=>0,'memo'=>$memo];
    }

    private function credit(string $accountKey, float $amount, ?string $memo = null): array
    {
        return ['account_key'=>$accountKey,'debit'=>0,'credit'=>round($amount,2),'memo'=>$memo];
    }
}
