<?php

namespace App\Providers;

use App\Models\CommissionEarning;
use App\Models\EmployeeCashAdvanceLedger;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Payment;
use App\Models\PaymentApplication;
use App\Models\PayrollRun;
use App\Models\Sale;
use App\Models\ServiceCreditLedger;
use App\Models\TransactionActionRequest;
use App\Models\TransactionRefund;
use App\Services\Accounting\OperationalAccountingService;
use App\Services\Accounting\OpeningReceivableService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Sale::saved(function (Sale $sale) {
            if ($sale->financial_finalized_at) {
                app(OperationalAccountingService::class)->postSaleRecognition($sale->fresh());
            }
        });

        Payment::created(function (Payment $payment) {
            app(OperationalAccountingService::class)->postPaymentReceipt($payment->fresh());
        });
        Payment::updated(function (Payment $payment) {
            if (strtoupper((string) $payment->status) === 'REVERSED') {
                app(OperationalAccountingService::class)->reversePaymentReceipt($payment->fresh());
            }
        });

        PaymentApplication::created(function (PaymentApplication $application) {
            app(OperationalAccountingService::class)->postPaymentApplication($application->fresh(), false);
            if ($application->opening_customer_receivable_id) {
                app(OpeningReceivableService::class)->sync((int) $application->opening_customer_receivable_id);
            }
        });
        PaymentApplication::updated(function (PaymentApplication $application) {
            if (strtoupper((string) $application->status) === 'REVERSED') {
                app(OperationalAccountingService::class)->postPaymentApplication($application->fresh(), true);
            }
            if ($application->opening_customer_receivable_id) {
                app(OpeningReceivableService::class)->sync((int) $application->opening_customer_receivable_id);
            }
        });

        InventoryMovement::created(function (InventoryMovement $movement) {
            app(OperationalAccountingService::class)->postInventoryMovement($movement->fresh());
        });

        InventoryReceipt::saved(function (InventoryReceipt $receipt) {
            app(OperationalAccountingService::class)->postInventoryReceipt($receipt->fresh());
        });

        CommissionEarning::created(function (CommissionEarning $earning) {
            app(OperationalAccountingService::class)->postCommission($earning->fresh());
        });
        CommissionEarning::updated(function (CommissionEarning $earning) {
            if (strtoupper((string) $earning->status) === 'REVERSED') {
                app(OperationalAccountingService::class)->reverseCommission($earning->fresh());
            }
        });

        PayrollRun::updated(function (PayrollRun $run) {
            $service = app(OperationalAccountingService::class);
            if (strtoupper((string) $run->status) === 'APPROVED') $service->postPayrollApproval($run->fresh());
            if (strtoupper((string) $run->status) === 'PAID') $service->postPayrollPayment($run->fresh());
        });

        EmployeeCashAdvanceLedger::created(function (EmployeeCashAdvanceLedger $entry) {
            app(OperationalAccountingService::class)->postCashAdvanceLedger($entry->fresh());
        });

        ServiceCreditLedger::created(function (ServiceCreditLedger $movement) {
            app(OperationalAccountingService::class)->postServiceCreditMovement($movement->fresh());
        });

        TransactionActionRequest::updated(function (TransactionActionRequest $request) {
            if (strtoupper((string) $request->status) === 'POSTED') {
                app(OperationalAccountingService::class)->postTransactionAction($request->fresh());
            }
        });

        TransactionRefund::created(function (TransactionRefund $refund) {
            if (strtoupper((string) $refund->status) === 'POSTED') {
                app(OperationalAccountingService::class)->postCashRefund($refund->fresh());
            }
        });

        TransactionRefund::updated(function (TransactionRefund $refund) {
            if (strtoupper((string) $refund->status) === 'POSTED') {
                app(OperationalAccountingService::class)->postCashRefund($refund->fresh());
            }
        });
    }
}
