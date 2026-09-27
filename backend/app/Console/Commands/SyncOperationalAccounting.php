<?php

namespace App\Console\Commands;

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
use Illuminate\Console\Command;

class SyncOperationalAccounting extends Command
{
    protected $signature = 'accounting:sync-current-state {--business=}';
    protected $description = 'Backfill idempotent accounting journals from the current operational state.';

    public function handle(OperationalAccountingService $accounting): int
    {
        $businessId = $this->option('business');
        $scope = fn ($q) => $businessId ? $q->where('business_id', (int) $businessId) : $q;

        $this->components->info('Syncing current operational state into accounting journals...');

        $scope(Sale::query())->whereNotNull('financial_finalized_at')->where('status','<>','REVERSED')->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postSaleRecognition($row)));
        $scope(Payment::query())->where('status','RECEIVED')->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postPaymentReceipt($row)));
        PaymentApplication::query()->where('status','ACTIVE')->whereHas('payment', fn ($q) => $businessId ? $q->where('business_id',(int)$businessId) : $q)->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postPaymentApplication($row, false)));
        $scope(InventoryReceipt::query())->where('status','POSTED')->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postInventoryReceipt($row)));
        $scope(InventoryMovement::query())->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postInventoryMovement($row)));
        $scope(CommissionEarning::query())->orderBy('id')->chunkById(100, function ($rows) use ($accounting) { $rows->each(function ($row) use ($accounting) { $accounting->postCommission($row); if ($row->status === 'REVERSED') $accounting->reverseCommission($row); }); });
        $scope(PayrollRun::query())->whereIn('status',['APPROVED','PAID'])->orderBy('id')->chunkById(100, function ($rows) use ($accounting) { $rows->each(function ($row) use ($accounting) { $accounting->postPayrollApproval($row); if ($row->status === 'PAID') $accounting->postPayrollPayment($row); }); });
        $scope(EmployeeCashAdvanceLedger::query())->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postCashAdvanceLedger($row)));
        ServiceCreditLedger::query()->whereHas('packageEnrollment', fn ($q) => $businessId ? $q->where('business_id',(int)$businessId) : $q)->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postServiceCreditMovement($row)));
        // Safe targeted repair/backfill for package-origin corrections/reversals. This
        // does not replay historical transaction-action deltas; it only posts the
        // distinct idempotent package-revenue reversal event when recognized package
        // revenue remains tied to a cancelled originating package.
        $scope(TransactionActionRequest::query())
            ->where('status', 'POSTED')
            ->whereIn('action_type', ['CORRECTION', 'REVERSAL'])
            ->orderBy('id')
            ->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postPackageOriginRevenueReversal($row)));
        $scope(TransactionRefund::query())->where('status','POSTED')->orderBy('id')->chunkById(100, fn ($rows) => $rows->each(fn ($row) => $accounting->postCashRefund($row)));

        $this->components->info('Current-state accounting sync complete. Existing journal source keys make this command safe to rerun.');
        $this->components->warn('Historical corrections are represented using the current corrected sale state; use a controlled migration if exact prior-period reconstruction is required.');
        return self::SUCCESS;
    }
}
