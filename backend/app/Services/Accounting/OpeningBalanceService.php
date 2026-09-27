<?php

namespace App\Services\Accounting;

use App\Models\AccountingOpeningBalance;
use App\Models\AccountingProductMapping;
use App\Models\ChartOfAccount;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\InventoryStock;
use App\Models\OpeningCustomerReceivable;
use App\Models\PackageEnrollment;
use App\Models\RetailProduct;
use App\Models\Service;
use App\Models\ServiceCredit;
use App\Models\ServiceCreditLedger;
use App\Models\ServicePrice;
use App\Models\User;
use App\Services\Foundation\CodeSequenceService;
use DomainException;
use Illuminate\Support\Facades\DB;

class OpeningBalanceService
{
    private const EPSILON = 0.004;

    public function __construct(
        private readonly AccountingJournalService $journals,
        private readonly CodeSequenceService $codes,
    ) {}

    public function bootstrap(User $actor, ?int $branchId = null): array
    {
        $this->assertSuperAdmin($actor);
        $businessId = (int) $actor->business_id;
        $branches = DB::table('branches')->where('business_id', $businessId)->where('status', 'ACTIVE')->orderBy('name')->get(['id','name']);
        $selectedBranchId = $branchId ?: (int) ($actor->default_branch_id ?: ($branches->first()->id ?? 0));
        $branch = $branches->firstWhere('id', $selectedBranchId);
        if (! $branch && $selectedBranchId) throw new DomainException('Select a valid active branch.');

        $cashAccounts = ChartOfAccount::query()
            ->where('business_id', $businessId)->where('active', true)->where('posting_type', 'POSTING')
            ->where('is_cash_account', true)->orderBy('code')->get(['id','code','name','system_key']);

        $products = RetailProduct::query()
            ->where('business_id', $businessId)->where('status', 'ACTIVE')->where('stock_type', 'INVENTORY')
            ->orderBy('name')->get()
            ->map(function (RetailProduct $product) use ($businessId, $selectedBranchId) {
                $mapping = $this->ensureProductMapping($businessId, $product);
                $stock = $selectedBranchId ? InventoryStock::query()->where('branch_id', $selectedBranchId)->where('retail_product_id', $product->id)->first() : null;
                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'basic_uom' => $product->unit ?: 'pc',
                    'current_quantity' => (float) ($stock?->on_hand ?? 0),
                    'unit_cost' => (float) ($product->basic_uom_cost ?? $product->unit_cost ?? 0),
                    'inventory_account_id' => $mapping?->inventory_account_id,
                    'inventory_account' => $mapping?->inventoryAccount ? $mapping->inventoryAccount->code.' · '.$mapping->inventoryAccount->name : null,
                ];
            })->values();

        $customers = Customer::query()
            ->where('business_id', $businessId)
            ->where('status', 'ACTIVE')
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id','code','first_name','last_name','mobile_number'])
            ->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'code' => $customer->code,
                'name' => $customer->full_name,
                'mobile_number' => $customer->mobile_number,
            ])->values();

        $packageServices = Service::query()
            ->with(['statusReference','serviceCount'])
            ->where('business_id', $businessId)
            ->whereHas('statusReference', fn ($query) => $query->where('code', 'ACTIVE'))
            ->orderBy('name')
            ->get()
            ->filter(fn (Service $service) => $this->isPackageService($service))
            ->map(fn (Service $service) => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'total_sessions' => max(1, (int) $service->number_of_sessions),
                'current_price' => $this->servicePrice($service->id, $selectedBranchId),
            ])->values();

        $existing = $selectedBranchId ? AccountingOpeningBalance::query()
            ->where('business_id', $businessId)->where('branch_id', $selectedBranchId)
            ->with(['branch','offsetAccount','journalEntry'])->first() : null;

        $operationalJournalCount = $selectedBranchId ? DB::table('accounting_journal_entries')
            ->where('business_id', $businessId)->where('branch_id', $selectedBranchId)->where('status', 'POSTED')
            ->where('source_type', '!=', 'OPENING_BALANCE')->count() : 0;
        $operationalMovementCount = $selectedBranchId ? InventoryMovement::query()
            ->where('business_id', $businessId)->where('branch_id', $selectedBranchId)
            ->where('movement_type', '!=', 'OPENING_BALANCE')->count() : 0;

        return [
            'branches' => $branches,
            'selected_branch_id' => $selectedBranchId,
            'cash_accounts' => $cashAccounts,
            'products' => $products,
            'customers' => $customers,
            'package_services' => $packageServices,
            'existing' => $existing ? $this->serialize($existing) : null,
            'readiness' => [
                'can_post' => ! $existing && $operationalJournalCount === 0 && $operationalMovementCount === 0,
                'operational_journals' => $operationalJournalCount,
                'operational_inventory_movements' => $operationalMovementCount,
            ],
        ];
    }

    public function post(User $actor, array $data): AccountingOpeningBalance
    {
        $this->assertSuperAdmin($actor);
        $businessId = (int) $actor->business_id;
        $branchId = (int) $data['branch_id'];

        $branchExists = DB::table('branches')->where('business_id', $businessId)->where('status', 'ACTIVE')->where('id', $branchId)->exists();
        if (! $branchExists) throw new DomainException('Select a valid active branch.');
        if (AccountingOpeningBalance::query()->where('business_id', $businessId)->where('branch_id', $branchId)->exists()) {
            throw new DomainException('Opening balances have already been posted for this branch. Use normal accounting and inventory adjustment workflows for later changes.');
        }

        $operationalJournals = DB::table('accounting_journal_entries')->where('business_id', $businessId)->where('branch_id', $branchId)->where('status', 'POSTED')->where('source_type', '!=', 'OPENING_BALANCE')->exists();
        $operationalInventory = InventoryMovement::query()->where('business_id', $businessId)->where('branch_id', $branchId)->where('movement_type', '!=', 'OPENING_BALANCE')->exists();
        if ($operationalJournals || $operationalInventory) {
            throw new DomainException('Opening balances are a cutover setup and can only be posted before operational transactions exist for this branch. For a hard restart, run the approved Full Development Reset first, then post the opening balances before normal operations resume.');
        }

        $offset = $this->journals->accountByKey($businessId, 'OPENING_BALANCE_EQUITY');
        if (! $offset) throw new DomainException('Opening Balance Equity account is missing. Run the latest migration and try again.');

        return DB::transaction(function () use ($actor, $businessId, $branchId, $data, $offset) {
            $cashSnapshot = [];
            $inventorySnapshot = [];
            $receivablesSnapshot = [];
            $packagesSnapshot = [];
            $journalLines = [];
            $cashTotal = 0.0;
            $inventoryTotal = 0.0;
            $receivablesTotal = 0.0;
            $deferredPackageTotal = 0.0;

            foreach (collect($data['cash_balances'] ?? []) as $row) {
                $amount = round(max(0, (float) ($row['amount'] ?? 0)), 2);
                if ($amount <= self::EPSILON) continue;
                $account = ChartOfAccount::query()->where('business_id', $businessId)->whereKey((int) ($row['account_id'] ?? 0))
                    ->where('active', true)->where('posting_type', 'POSTING')->where('is_cash_account', true)->first();
                if (! $account) throw new DomainException('One of the selected beginning cash accounts is not a valid active cash account.');
                $cashSnapshot[] = ['account_id'=>$account->id,'code'=>$account->code,'name'=>$account->name,'amount'=>$amount];
                $cashTotal = round($cashTotal + $amount, 2);
                $journalLines[] = ['account_id'=>$account->id,'debit'=>$amount,'credit'=>0,'memo'=>'Opening balance · '.$account->name];
            }

            foreach (collect($data['inventory_balances'] ?? []) as $row) {
                $quantity = round(max(0, (float) ($row['quantity'] ?? 0)), 4);
                if ($quantity <= 0.00004) continue;
                $unitCost = round(max(0, (float) ($row['unit_cost'] ?? 0)), 4);
                if ($unitCost <= 0.00004) throw new DomainException('Beginning inventory with quantity must have a Basic UOM cost greater than zero.');
                $product = RetailProduct::query()->where('business_id', $businessId)->whereKey((int) ($row['retail_product_id'] ?? 0))
                    ->where('status', 'ACTIVE')->where('stock_type', 'INVENTORY')->first();
                if (! $product) throw new DomainException('One of the beginning inventory items is not an active tracked inventory item.');
                $mapping = $this->ensureProductMapping($businessId, $product);
                if (! $mapping?->inventoryAccount) throw new DomainException('Map '.$product->name.' to an Inventory account under Accounting Mapping before posting opening inventory.');

                $amount = round($quantity * $unitCost, 2);
                $inventoryTotal = round($inventoryTotal + $amount, 2);
                $inventorySnapshot[] = [
                    'retail_product_id'=>$product->id,'sku'=>$product->sku,'name'=>$product->name,'basic_uom'=>$product->unit,
                    'quantity'=>$quantity,'unit_cost'=>$unitCost,'amount'=>$amount,'inventory_account_id'=>$mapping->inventory_account_id,
                    'inventory_account'=>$mapping->inventoryAccount->code.' · '.$mapping->inventoryAccount->name,
                ];

                $stock = InventoryStock::query()->firstOrCreate(
                    ['business_id'=>$businessId,'branch_id'=>$branchId,'retail_product_id'=>$product->id],
                    ['on_hand'=>0,'reserved'=>0]
                );
                if (abs((float) $stock->on_hand) > 0.00004 || abs((float) $stock->reserved) > 0.00004) {
                    throw new DomainException($product->name.' already has stock activity. Use Audit Count or Inventory Adjustment instead of an opening balance.');
                }
                $before = (float) $stock->on_hand;
                $stock->update(['on_hand'=>$quantity,'reserved'=>0]);
                InventoryMovement::query()->create([
                    'business_id'=>$businessId,'branch_id'=>$branchId,'retail_product_id'=>$product->id,
                    'movement_type'=>'OPENING_BALANCE','quantity_change'=>$quantity,'quantity_before'=>$before,'quantity_after'=>$quantity,
                    'unit_cost_snapshot'=>$unitCost,'reference_code'=>'OPENING-'.$data['cutover_date'],'reason'=>'Beginning inventory at system cutover',
                    'created_by'=>$actor->id,'movement_at'=>$data['cutover_date'].' 00:00:00',
                ]);

                $purchaseFactor = max(0.000001, (float) ($product->purchase_to_basic_factor ?? 1));
                $product->update([
                    'unit_cost'=>$unitCost,
                    'basic_uom_cost'=>$unitCost,
                    'purchase_uom_cost'=>round($unitCost * $purchaseFactor, 4),
                    'updated_by'=>$actor->id,
                ]);
            }

            foreach (collect($inventorySnapshot)->groupBy('inventory_account_id') as $accountId => $rows) {
                $amount = round((float) collect($rows)->sum('amount'), 2);
                if ($amount > self::EPSILON) $journalLines[] = ['account_id'=>(int)$accountId,'debit'=>$amount,'credit'=>0,'memo'=>'Beginning inventory at system cutover'];
            }

            $preparedReceivables = [];
            foreach (collect($data['customer_receivables'] ?? []) as $row) {
                $amount = round(max(0, (float) ($row['amount'] ?? 0)), 2);
                if ($amount <= self::EPSILON) continue;
                $customer = $this->customer($businessId, (int) ($row['customer_id'] ?? 0));
                $preparedReceivables[] = [
                    'customer'=>$customer,
                    'amount'=>$amount,
                    'reference'=>$this->nullableTrim($row['reference'] ?? null),
                    'notes'=>$this->nullableTrim($row['notes'] ?? null),
                ];
                $receivablesTotal = round($receivablesTotal + $amount, 2);
            }

            $preparedPackages = [];
            foreach (collect($data['active_packages'] ?? []) as $row) {
                $customer = $this->customer($businessId, (int) ($row['customer_id'] ?? 0));
                $service = Service::query()->with(['statusReference','serviceCount'])
                    ->where('business_id', $businessId)->whereKey((int) ($row['service_id'] ?? 0))
                    ->whereHas('statusReference', fn ($query) => $query->where('code', 'ACTIVE'))->first();
                if (! $service || ! $this->isPackageService($service)) throw new DomainException('Select a valid active multi-session service for each opening package.');

                $totalSessions = max(1, (int) $service->number_of_sessions);
                $remainingSessions = max(0, (int) ($row['remaining_sessions'] ?? 0));
                if ($remainingSessions < 1 || $remainingSessions > $totalSessions) {
                    throw new DomainException($service->name.' must have remaining sessions between 1 and '.$totalSessions.'.');
                }
                $remainingValue = round(max(0, (float) ($row['remaining_service_value'] ?? 0)), 2);
                if ($remainingValue <= self::EPSILON) throw new DomainException($service->name.' must have a Remaining Service Value greater than zero.');
                $outstanding = round(max(0, (float) ($row['outstanding_balance'] ?? 0)), 2);
                $impliedPackageValue = round(($remainingValue / $remainingSessions) * $totalSessions, 2);
                $usedSessions = max(0, $totalSessions - $remainingSessions);

                $preparedPackages[] = [
                    'customer'=>$customer,
                    'service'=>$service,
                    'total_sessions'=>$totalSessions,
                    'used_sessions'=>$usedSessions,
                    'remaining_sessions'=>$remainingSessions,
                    'remaining_service_value'=>$remainingValue,
                    'implied_package_value'=>$impliedPackageValue,
                    'outstanding_balance'=>$outstanding,
                    'expiry_date'=>$row['expiry_date'] ?? null,
                    'notes'=>$this->nullableTrim($row['notes'] ?? null),
                ];
                $deferredPackageTotal = round($deferredPackageTotal + $remainingValue, 2);
                $receivablesTotal = round($receivablesTotal + $outstanding, 2);
            }

            if ($receivablesTotal > self::EPSILON) {
                $ar = $this->journals->accountByKey($businessId, 'AR_CUSTOMERS');
                if (! $ar) throw new DomainException('Accounts Receivable account is missing from the Chart of Accounts.');
                $journalLines[] = ['account_id'=>$ar->id,'debit'=>$receivablesTotal,'credit'=>0,'memo'=>'Opening customer receivables'];
            }

            if ($deferredPackageTotal > self::EPSILON) {
                $deferred = $this->journals->accountByKey($businessId, 'DEFERRED_PACKAGE_REVENUE');
                if (! $deferred) throw new DomainException('Deferred Package Revenue account is missing from the Chart of Accounts.');
                $journalLines[] = ['account_id'=>$deferred->id,'debit'=>0,'credit'=>$deferredPackageTotal,'memo'=>'Unused package sessions at system cutover'];
            }

            $openingMagnitude = round($cashTotal + $inventoryTotal + $receivablesTotal + $deferredPackageTotal, 2);
            if ($openingMagnitude <= self::EPSILON) {
                throw new DomainException('Enter at least one beginning cash balance, inventory quantity, customer balance, or active package.');
            }

            $debitTotal = round($cashTotal + $inventoryTotal + $receivablesTotal, 2);
            $creditTotal = round($deferredPackageTotal, 2);
            $equityDifference = round($debitTotal - $creditTotal, 2);
            if ($equityDifference > self::EPSILON) {
                $journalLines[] = ['account_id'=>$offset->id,'debit'=>0,'credit'=>$equityDifference,'memo'=>'Opening balance equity / cutover clearing'];
            } elseif ($equityDifference < -self::EPSILON) {
                $journalLines[] = ['account_id'=>$offset->id,'debit'=>abs($equityDifference),'credit'=>0,'memo'=>'Opening balance equity / cutover clearing'];
            }

            $opening = AccountingOpeningBalance::query()->create([
                'business_id'=>$businessId,'branch_id'=>$branchId,'cutover_date'=>$data['cutover_date'],
                'cash_snapshot'=>$cashSnapshot,'inventory_snapshot'=>$inventorySnapshot,
                'receivables_snapshot'=>[],'packages_snapshot'=>[],
                'cash_total'=>$cashTotal,'inventory_total'=>$inventoryTotal,'receivables_total'=>$receivablesTotal,'deferred_package_total'=>$deferredPackageTotal,
                'offset_account_id'=>$offset->id,'status'=>'POSTED','notes'=>$data['notes'] ?? null,'created_by'=>$actor->id,'posted_at'=>now(),
            ]);

            foreach ($preparedReceivables as $prepared) {
                $receivable = $this->createOpeningReceivable(
                    $actor, $opening, $prepared['customer'], null, 'GENERAL', $prepared['amount'], $prepared['reference'], $prepared['notes']
                );
                $receivablesSnapshot[] = $this->receivableSnapshot($receivable);
            }

            foreach ($preparedPackages as $prepared) {
                /** @var Customer $customer */
                $customer = $prepared['customer'];
                /** @var Service $service */
                $service = $prepared['service'];
                $monthYear = date('my', strtotime($data['cutover_date']));

                $enrollment = PackageEnrollment::query()->create([
                    'business_id'=>$businessId,
                    'code'=>$this->codes->next('PACKAGE_ENROLLMENT:'.$monthYear, 'PKE-'.$monthYear.'-', 7, $businessId),
                    'customer_id'=>$customer->id,
                    'service_id'=>$service->id,
                    'service_name_snapshot'=>$service->name,
                    'total_sessions'=>$prepared['total_sessions'],
                    'package_price_snapshot'=>$prepared['implied_package_value'],
                    'package_gross_price_snapshot'=>$prepared['implied_package_value'],
                    'opening_balance_id'=>$opening->id,
                    'sessions_completed_before_activation'=>$prepared['used_sessions'],
                    'status'=>'ACTIVE',
                    'expiry_date'=>$prepared['expiry_date'],
                    'activated_at'=>$data['cutover_date'].' 00:00:00',
                    'created_by'=>$actor->id,
                    'updated_by'=>$actor->id,
                ]);

                $credit = ServiceCredit::query()->create([
                    'business_id'=>$businessId,
                    'code'=>$this->codes->next('SERVICE_CREDIT:'.$monthYear, 'SCR-'.$monthYear.'-', 7, $businessId),
                    'package_enrollment_id'=>$enrollment->id,
                    'customer_id'=>$customer->id,
                    'service_id'=>$service->id,
                    'service_name_snapshot'=>$service->name,
                    'sessions_purchased'=>$prepared['total_sessions'],
                    'sessions_used'=>$prepared['used_sessions'],
                    'sessions_remaining'=>$prepared['remaining_sessions'],
                    'status'=>'ACTIVE',
                    'expiry_date'=>$prepared['expiry_date'],
                    'created_by'=>$actor->id,
                    'updated_by'=>$actor->id,
                ]);

                ServiceCreditLedger::query()->create([
                    'business_id'=>$businessId,
                    'code'=>$this->codes->next('SERVICE_CREDIT_LEDGER:'.$monthYear, 'SCM-'.$monthYear.'-', 8, $businessId),
                    'movement_key'=>'OPENING_BALANCE:'.$opening->id.':PACKAGE:'.$enrollment->id,
                    'service_credit_id'=>$credit->id,
                    'package_enrollment_id'=>$enrollment->id,
                    'customer_id'=>$customer->id,
                    'service_id'=>$service->id,
                    'movement_type'=>'OPENING_BALANCE',
                    'sessions'=>$prepared['remaining_sessions'],
                    'notes'=>'Remaining package sessions established at system cutover.',
                    'processed_by'=>$actor->id,
                ]);

                $openingReceivable = null;
                if ($prepared['outstanding_balance'] > self::EPSILON) {
                    $openingReceivable = $this->createOpeningReceivable(
                        $actor, $opening, $customer, $enrollment, 'PACKAGE', $prepared['outstanding_balance'],
                        $enrollment->code, $prepared['notes']
                    );
                    $receivablesSnapshot[] = $this->receivableSnapshot($openingReceivable);
                }

                $packagesSnapshot[] = [
                    'package_enrollment_id'=>$enrollment->id,
                    'package_code'=>$enrollment->code,
                    'service_credit_id'=>$credit->id,
                    'service_credit_code'=>$credit->code,
                    'customer_id'=>$customer->id,
                    'customer'=>$customer->full_name,
                    'service_id'=>$service->id,
                    'service'=>$service->name,
                    'total_sessions'=>$prepared['total_sessions'],
                    'sessions_used_before_cutover'=>$prepared['used_sessions'],
                    'remaining_sessions'=>$prepared['remaining_sessions'],
                    'remaining_service_value'=>$prepared['remaining_service_value'],
                    'implied_package_value'=>$prepared['implied_package_value'],
                    'outstanding_balance'=>$prepared['outstanding_balance'],
                    'opening_receivable_id'=>$openingReceivable?->id,
                    'expiry_date'=>$prepared['expiry_date'],
                ];
            }

            $opening->update([
                'receivables_snapshot'=>$receivablesSnapshot,
                'packages_snapshot'=>$packagesSnapshot,
            ]);

            $journal = $this->journals->postEntry(
                $businessId,$branchId,$data['cutover_date'],'OPENING_BALANCE',(int)$opening->id,'POST',
                'OPENING-'.$opening->id,'Opening balances at system cutover',$journalLines,$actor->id
            );
            if (! $journal) throw new DomainException('Unable to post the opening balance journal. Verify the Chart of Accounts setup.');
            $opening->update(['journal_entry_id'=>$journal->id]);

            return $opening->fresh(['branch','offsetAccount','journalEntry']);
        });
    }

    public function serialize(AccountingOpeningBalance $row): array
    {
        $assets = round((float)$row->cash_total + (float)$row->inventory_total + (float)$row->receivables_total, 2);
        $deferred = round((float)$row->deferred_package_total, 2);
        $equity = round($assets - $deferred, 2);

        return [
            'id'=>$row->id,'branch_id'=>$row->branch_id,'branch'=>$row->branch?->name,'cutover_date'=>$row->cutover_date?->toDateString(),
            'cash_total'=>(float)$row->cash_total,'inventory_total'=>(float)$row->inventory_total,
            'receivables_total'=>(float)$row->receivables_total,'deferred_package_total'=>(float)$row->deferred_package_total,
            'total'=>$assets,'total_assets'=>$assets,'opening_equity'=>$equity,
            'cash_snapshot'=>$row->cash_snapshot ?: [],'inventory_snapshot'=>$row->inventory_snapshot ?: [],
            'receivables_snapshot'=>$row->receivables_snapshot ?: [],'packages_snapshot'=>$row->packages_snapshot ?: [],
            'offset_account'=>$row->offsetAccount ? $row->offsetAccount->code.' · '.$row->offsetAccount->name : null,
            'journal_code'=>$row->journalEntry?->code,'status'=>$row->status,'notes'=>$row->notes,'posted_at'=>$row->posted_at?->toIso8601String(),
        ];
    }

    private function createOpeningReceivable(
        User $actor,
        AccountingOpeningBalance $opening,
        Customer $customer,
        ?PackageEnrollment $package,
        string $type,
        float $amount,
        ?string $reference,
        ?string $notes,
    ): OpeningCustomerReceivable {
        $monthYear = $opening->cutover_date?->format('my') ?: now()->format('my');
        return OpeningCustomerReceivable::query()->create([
            'business_id'=>$opening->business_id,
            'branch_id'=>$opening->branch_id,
            'opening_balance_id'=>$opening->id,
            'customer_id'=>$customer->id,
            'package_enrollment_id'=>$package?->id,
            'code'=>$this->codes->next('OPENING_AR:'.$monthYear, 'OAR-'.$monthYear.'-', 7, (int)$opening->business_id),
            'balance_type'=>strtoupper($type),
            'cutover_date'=>$opening->cutover_date?->toDateString(),
            'reference'=>$reference,
            'original_amount'=>round($amount, 2),
            'remaining_balance'=>round($amount, 2),
            'status'=>'OPEN',
            'notes'=>$notes,
            'created_by'=>$actor->id,
        ]);
    }

    private function receivableSnapshot(OpeningCustomerReceivable $receivable): array
    {
        $receivable->loadMissing(['customer','packageEnrollment']);
        return [
            'id'=>$receivable->id,
            'code'=>$receivable->code,
            'balance_type'=>$receivable->balance_type,
            'customer_id'=>$receivable->customer_id,
            'customer'=>$receivable->customer?->full_name,
            'package_enrollment_id'=>$receivable->package_enrollment_id,
            'package_code'=>$receivable->packageEnrollment?->code,
            'reference'=>$receivable->reference,
            'amount'=>(float)$receivable->original_amount,
        ];
    }

    private function customer(int $businessId, int $customerId): Customer
    {
        $customer = Customer::query()->where('business_id', $businessId)->where('status', 'ACTIVE')->whereKey($customerId)->first();
        if (! $customer) throw new DomainException('Select a valid active customer for each opening customer balance or package.');
        return $customer;
    }

    private function isPackageService(Service $service): bool
    {
        $countCode = strtoupper((string) $service->serviceCount?->code);
        return (int) $service->number_of_sessions > 1 || in_array($countCode, ['MULTI_SESSION','MULTIPLE_SESSION','MULTIPLE_SESSIONS'], true);
    }

    private function servicePrice(int $serviceId, ?int $branchId): ?float
    {
        $today = now()->toDateString();
        $price = ServicePrice::query()
            ->where('service_id', $serviceId)
            ->whereDate('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->where(function ($query) use ($branchId) {
                if ($branchId) $query->where('branch_id', $branchId)->orWhereNull('branch_id');
                else $query->whereNull('branch_id');
            })
            ->orderByRaw('branch_id is null')
            ->orderByDesc('effective_from')
            ->first();
        return $price ? (float) $price->standard_price : null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function assertSuperAdmin(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->isSuperAdmin()) throw new DomainException('Only an active Super Admin can manage system opening balances.');
    }

    private function productMapping(int $businessId, RetailProduct $product): ?AccountingProductMapping
    {
        return AccountingProductMapping::query()->where('business_id',$businessId)->where('retail_product_id',$product->id)->with('inventoryAccount')->first();
    }

    private function ensureProductMapping(int $businessId, RetailProduct $product): ?AccountingProductMapping
    {
        $existing = $this->productMapping($businessId, $product);
        if ($existing) return $existing;
        $category = strtoupper((string) ($product->category ?? ''));
        if ($product->retail_available) {
            $inventoryKey='INVENTORY_RETAIL_PRODUCTS'; $serviceCostKey='COS_SERVICE_CONSUMABLES';
        } elseif (str_contains($category,'BARBER') || str_contains($category,'CONSUMABLE')) {
            $inventoryKey='INVENTORY_BARBER_CONSUMABLES'; $serviceCostKey='COS_SERVICE_CONSUMABLES';
        } else {
            $inventoryKey='INVENTORY_TREATMENT_CHEMICALS'; $serviceCostKey='COS_SERVICE_CHEMICALS';
        }
        $inventory=$this->journals->accountByKey($businessId,$inventoryKey);
        $serviceCost=$this->journals->accountByKey($businessId,$serviceCostKey);
        $retailCost=$this->journals->accountByKey($businessId,'COS_RETAIL_PRODUCTS');
        if (! $inventory || ! $serviceCost || ! $retailCost) return null;
        return AccountingProductMapping::query()->create([
            'business_id'=>$businessId,'retail_product_id'=>$product->id,'inventory_account_id'=>$inventory->id,
            'service_cost_account_id'=>$serviceCost->id,'retail_cost_account_id'=>$retailCost->id,
        ])->load('inventoryAccount');
    }
}
