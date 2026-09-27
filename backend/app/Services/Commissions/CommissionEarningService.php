<?php

namespace App\Services\Commissions;

use App\Models\CommissionEarning;
use App\Models\CommissionRule;
use App\Models\Personnel;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ServicePrice;
use App\Models\TransactionActionRequest;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Hr\DailyCompensationService;
use App\Services\Security\AuditService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommissionEarningService
{
    private const EPSILON = 0.009;

    public function __construct(
        private readonly CommissionRuleService $rules,
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
        private readonly DailyCompensationService $dailyCompensation,
    ) {
    }

    public function reconcileSale(User $actor, Sale $sale): Collection
    {
        if ((int) $sale->business_id !== (int) $actor->business_id) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $sale) {
            $sale = Sale::query()
                ->whereKey($sale->id)
                ->with([
                    'items.service.category',
                    'items.assignedPersonnel.position',
                    'items.visitServiceLine.packageEnrollment',
                    'items.visitRetailLine',
                    'items.retailProduct',
                    'items.packageEnrollment',
                    'visit',
                    'booking',
                    'customer',
                ])
                ->lockForUpdate()
                ->firstOrFail();

            $financiallyValid = $sale->financial_finalized_at !== null
                && in_array(strtoupper((string) $sale->status), ['PAID', 'ON_ACCOUNT', 'SERVICE_CREDIT'], true);

            $validSaleItemIds = [];
            $touchedDays = [];

            if ($financiallyValid) {
                foreach ($sale->items as $item) {
                    $itemType = strtoupper((string) $item->item_type);

                    if ($itemType === 'PRODUCT') {
                        $retailLine = $item->visitRetailLine;
                        $product = $item->retailProduct;
                        $personnel = $item->assignedPersonnel;

                        // Visit retail lines and direct Retail POS sale items can both earn retail commission.
                        if (! $personnel) {
                            continue;
                        }

                        if ($product && ! $product->commission_eligible) {
                            continue;
                        }

                        if (! $personnel->commission_eligible || strtoupper((string) $personnel->status) !== 'ACTIVE') {
                            continue;
                        }

                        $asOf = CarbonImmutable::parse($sale->financial_finalized_at ?: $sale->sale_date)
                            ->setTimezone($this->clock->timezone());
                        $workDate = $asOf->toDateString();
                        $rule = $this->rules->resolveRetailFor(
                            (int) $sale->business_id,
                            $personnel,
                            $asOf,
                            $sale->branch_id,
                            $product,
                        );

                        if (! $rule) {
                            continue;
                        }

                        $basisAmount = round(max(0, (float) ($item->net_line_total ?? $item->line_total ?? 0)), 2);
                        $commissionUnits = max(1, (int) $item->quantity);
                        $commissionAmount = $rule->calculation_type === 'FIXED'
                            ? round((float) $rule->rate_value * $commissionUnits, 2)
                            : round($basisAmount * ((float) $rule->rate_value / 100), 2);

                        $existingQuery = CommissionEarning::query()->lockForUpdate();
                        if ($retailLine) {
                            $existingQuery->where('visit_retail_line_id', $retailLine->id);
                        } else {
                            $existingQuery->where('sale_item_id', $item->id)->where('earning_type', 'RETAIL');
                        }
                        $existing = $existingQuery->first();
                        $economicAmount = $this->economicCommissionAmountForItemPersonnel($item, (int) $personnel->id);

                        if ($existing || abs($economicAmount) > self::EPSILON) {
                            if (abs($economicAmount - $commissionAmount) > self::EPSILON) {
                                throw new DomainException(
                                    'Commission integrity conflict detected for '.$item->item_name_snapshot.'. '.
                                    'Existing commission effects do not match the current retail commission target. '.
                                    'Checkout was stopped to prevent a duplicate or incorrect commission posting.'
                                );
                            }

                            $validSaleItemIds[] = $item->id;
                            $touchedDays[$personnel->id.'|'.$workDate] = [$personnel, $workDate];
                            continue;
                        }

                        $now = $this->clock->now();
                        $earning = CommissionEarning::query()->create([
                            'business_id' => $sale->business_id,
                            'code' => $this->codes->next(
                                'COMMISSION_EARNING:'.$now->format('my'),
                                'CME-'.$now->format('my').'-',
                                6,
                                $sale->business_id,
                            ),
                            'sale_id' => $sale->id,
                            'sale_item_id' => $item->id,
                            'visit_id' => $sale->visit_id,
                            'visit_service_line_id' => null,
                            'visit_retail_line_id' => $retailLine?->id,
                            'earning_type' => 'RETAIL',
                            'booking_id' => $sale->booking_id,
                            'customer_id' => $sale->customer_id,
                            'service_id' => null,
                            'personnel_id' => $personnel->id,
                            'commission_rule_id' => $rule->id,
                            'work_date' => $workDate,
                            'service_name_snapshot' => $item->item_name_snapshot,
                            'personnel_name_snapshot' => $item->assigned_personnel_name_snapshot ?: $personnel->full_name,
                            'basis' => 'NET_RETAIL_SALE',
                            'basis_amount' => $basisAmount,
                            'calculation_type' => $rule->calculation_type,
                            'rate_value' => (float) $rule->rate_value,
                            'commission_amount' => $commissionAmount,
                            'status' => 'OPEN',
                            'created_by' => $actor->id,
                        ]);

                        $validSaleItemIds[] = $item->id;
                        $touchedDays[$personnel->id.'|'.$workDate] = [$personnel, $workDate];

                        $this->audit->record(
                            action: 'CREATE_COMMISSION_EARNING',
                            module: 'commissions',
                            entityType: CommissionEarning::class,
                            entityId: $earning->id,
                            after: $this->snapshot($earning),
                            actor: $actor,
                        );
                        continue;
                    }

                    if (! in_array($itemType, ['SERVICE', 'PACKAGE', 'SERVICE_CREDIT_USE'], true)) {
                        continue;
                    }

                    $line = $item->visitServiceLine;
                    $personnel = $item->assignedPersonnel;
                    $service = $item->service;

                    if (! $line || strtoupper((string) $line->status) !== 'COMPLETED' || ! $personnel || ! $service) {
                        continue;
                    }

                    if (! $personnel->commission_eligible || strtoupper((string) $personnel->status) !== 'ACTIVE') {
                        continue;
                    }

                    $completedAt = $line->completed_at ?: $sale->sale_date ?: $sale->financial_finalized_at;
                    $asOf = CarbonImmutable::parse($completedAt)->setTimezone($this->clock->timezone());
                    $workDate = $asOf->toDateString();
                    $rule = $this->rules->resolveFor(
                        (int) $sale->business_id,
                        $personnel,
                        $service,
                        $asOf,
                        $sale->branch_id,
                    );

                    if (! $rule) {
                        continue;
                    }

                    [$basisAmount, $basisLabel, $commissionUnits] = $this->commissionBasisForItem(
                        $item,
                        (int) $sale->business_id,
                        (int) $service->id,
                        $workDate,
                        $sale->branch_id,
                    );

                    $commissionAmount = $rule->calculation_type === 'FIXED'
                        ? round((float) $rule->rate_value * $commissionUnits, 2)
                        : round($basisAmount * ((float) $rule->rate_value / 100), 2);

                    $existing = CommissionEarning::query()
                        ->where('visit_service_line_id', $line->id)
                        ->lockForUpdate()
                        ->first();
                    $economicAmount = $this->economicCommissionAmountForItemPersonnel($item, (int) $personnel->id);

                    if ($existing || abs($economicAmount) > self::EPSILON) {
                        if (abs($economicAmount - $commissionAmount) > self::EPSILON) {
                            throw new DomainException(
                                'Commission integrity conflict detected for '.$item->item_name_snapshot.'. '.
                                'Existing commission effects do not match the current service commission target. '.
                                'Checkout was stopped to prevent a duplicate or incorrect commission posting.'
                            );
                        }

                        $validSaleItemIds[] = $item->id;
                        $touchedDays[$personnel->id.'|'.$workDate] = [$personnel, $workDate];
                        continue;
                    }

                    $now = $this->clock->now();
                    $earning = CommissionEarning::query()->create([
                        'business_id' => $sale->business_id,
                        'code' => $this->codes->next(
                            'COMMISSION_EARNING:'.$now->format('my'),
                            'CME-'.$now->format('my').'-',
                            6,
                            $sale->business_id,
                        ),
                        'sale_id' => $sale->id,
                        'sale_item_id' => $item->id,
                        'visit_id' => $sale->visit_id,
                        'visit_service_line_id' => $line->id,
                        'visit_retail_line_id' => null,
                        'earning_type' => 'SERVICE',
                        'booking_id' => $sale->booking_id,
                        'customer_id' => $sale->customer_id,
                        'service_id' => $service->id,
                        'personnel_id' => $personnel->id,
                        'commission_rule_id' => $rule->id,
                        'work_date' => $workDate,
                        'service_name_snapshot' => $item->item_name_snapshot ?: $service->name,
                        'personnel_name_snapshot' => $item->assigned_personnel_name_snapshot ?: $personnel->full_name,
                        'basis' => $basisLabel,
                        'basis_amount' => round($basisAmount, 2),
                        'calculation_type' => $rule->calculation_type,
                        'rate_value' => (float) $rule->rate_value,
                        'commission_amount' => $commissionAmount,
                        'status' => 'OPEN',
                        'created_by' => $actor->id,
                    ]);

                    $validSaleItemIds[] = $item->id;
                    $touchedDays[$personnel->id.'|'.$workDate] = [$personnel, $workDate];

                    $this->audit->record(
                        action: 'CREATE_COMMISSION_EARNING',
                        module: 'commissions',
                        entityType: CommissionEarning::class,
                        entityId: $earning->id,
                        after: $this->snapshot($earning),
                        actor: $actor,
                    );
                }
            }

            $stale = CommissionEarning::query()
                ->where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->where('status', 'OPEN')
                ->when($validSaleItemIds, fn ($query) => $query->whereNotIn('sale_item_id', $validSaleItemIds))
                ->get();

            foreach ($stale as $earning) {
                $earning->update([
                    'status' => 'REVERSED',
                    'reversal_reason' => 'Commission source is no longer financially or operationally valid.',
                    'reversed_at' => $this->clock->now(),
                    'reversed_by' => $actor->id,
                ]);
                if ($earning->personnel) {
                    $touchedDays[$earning->personnel_id.'|'.$earning->work_date->toDateString()] = [
                        $earning->personnel,
                        $earning->work_date->toDateString(),
                    ];
                }
            }

            foreach ($touchedDays as [$personnel, $date]) {
                $this->dailyCompensation->recalculatePersonnelDay($personnel, $date);
            }

            return CommissionEarning::query()
                ->where('sale_id', $sale->id)
                ->with(['personnel', 'service', 'rule'])
                ->orderBy('id')
                ->get();
        });
    }

    public function list(User $actor, array $filters = []): array
    {
        $today = CarbonImmutable::parse($this->clock->today(), $this->clock->timezone());
        $from = $this->date($filters['from'] ?? null, $today->startOfMonth()->toDateString());
        $to = $this->date($filters['to'] ?? null, $today->toDateString());
        $personnelId = isset($filters['personnel_id']) && $filters['personnel_id'] !== ''
            ? (int) $filters['personnel_id']
            : null;
        $status = strtoupper(trim((string) ($filters['status'] ?? 'ALL')));
        $bookingCode = trim((string) ($filters['booking_code'] ?? ''));
        $ruleId = isset($filters['rule_id']) && $filters['rule_id'] !== '' ? (int) $filters['rule_id'] : null;

        $query = CommissionEarning::query()
            ->where('business_id', $actor->business_id)
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to)
            ->with(['personnel.position', 'service', 'rule', 'sale.booking', 'saleItem']);

        if ($personnelId) {
            $query->where('personnel_id', $personnelId);
        }
        if ($status !== '' && $status !== 'ALL') {
            if ($status === 'REVERSED') {
                $query->where(function ($statusQuery) {
                    $statusQuery->where('status', 'REVERSED')
                        ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->where('status', 'REVERSED'))
                        ->orWhere(function ($lineSourceQuery) {
                            // A historical source commission becomes effectively reversed when
                            // its Sale Item is removed/reversed by an approved transaction action.
                            $lineSourceQuery->where(function ($earningTypeQuery) {
                                $earningTypeQuery->whereNull('earning_type')
                                    ->orWhere('earning_type', '<>', 'ADJUSTMENT');
                            })->whereHas('saleItem', fn ($itemQuery) => $itemQuery
                                ->whereIn('correction_status', ['REMOVED', 'REVERSED']));
                        })
                        ->orWhere(function ($adjustmentQuery) {
                            // Negative transaction-action commission adjustments remain OPEN in
                            // the ledger so Payroll can consume them, but Commission Summary
                            // presents them as reversal entries for an intuitive audit view.
                            $adjustmentQuery->where('earning_type', 'ADJUSTMENT')
                                ->where('commission_amount', '<', 0)
                                ->whereNotNull('source_transaction_action_request_id');
                        });
                });
            } else {
                $query->where('status', $status)
                    ->whereDoesntHave('sale', fn ($saleQuery) => $saleQuery->where('status', 'REVERSED'))
                    ->where(function ($sourceQuery) {
                        $sourceQuery->where('earning_type', 'ADJUSTMENT')
                            ->orWhereDoesntHave('saleItem', fn ($itemQuery) => $itemQuery
                                ->whereIn('correction_status', ['REMOVED', 'REVERSED']));
                    });
                if ($status === 'OPEN') {
                    $query->where(function ($openQuery) {
                        $openQuery->where('earning_type', '<>', 'ADJUSTMENT')
                            ->orWhere('commission_amount', '>=', 0)
                            ->orWhereNull('source_transaction_action_request_id');
                    });
                }
            }
        }
        if ($bookingCode !== '') {
            $query->whereHas('sale.booking', fn ($booking) => $booking->where('code', 'like', '%'.$bookingCode.'%'));
        }
        if ($ruleId) {
            $query->where('commission_rule_id', $ruleId);
        }

        $rows = $query->orderByDesc('work_date')->orderByDesc('id')->get();
        $rows = $this->withoutRedundantCorrectionDuplicates($rows);

        // A full removal/reversal may require an OPEN negative adjustment in the ledger so
        // a future payroll can recover an amount that was already approved or paid. Keep
        // that counter-entry in the database/payroll waterfall, but do not show a duplicate
        // reversal line in Commission Summary. The original source earning is the single
        // historical row and is serialized as a negative REVERSED commission.
        $negativeAdjustmentSourceIds = $rows
            ->filter(fn (CommissionEarning $row) => $this->isNegativeTransactionAdjustment($row) && $row->source_commission_earning_id)
            ->pluck('source_commission_earning_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $fullyReversedSourceIds = $negativeAdjustmentSourceIds->isEmpty()
            ? collect()
            : CommissionEarning::query()
                ->whereIn('id', $negativeAdjustmentSourceIds->all())
                ->with(['sale', 'saleItem'])
                ->get()
                ->filter(fn (CommissionEarning $source) => $this->effectiveStatus($source) === 'REVERSED')
                ->pluck('id')
                ->map(fn ($id) => (int) $id);

        $visibleRows = $rows->reject(fn (CommissionEarning $row) =>
            $this->isNegativeTransactionAdjustment($row)
            && $row->source_commission_earning_id
            && $fullyReversedSourceIds->contains((int) $row->source_commission_earning_id)
        )->values();

        $effectiveRows = $rows->map(fn (CommissionEarning $row) => [
            'row' => $row,
            'status' => $this->effectiveStatus($row),
            'display_status' => $this->displayStatus($row),
        ]);
        $reversalRequestIds = TransactionActionRequest::query()
            ->where('business_id', $actor->business_id)
            ->where('action_type', 'REVERSAL')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'rows' => $visibleRows->map(fn (CommissionEarning $row) => $this->serialize($row))->values(),
            'totals' => [
                'open' => round((float) $effectiveRows
                    ->filter(fn (array $entry) => $entry['display_status'] === 'OPEN')
                    ->sum(fn (array $entry) => (float) $entry['row']->commission_amount), 2),
                'all_active' => round((float) $effectiveRows
                    ->reject(fn (array $entry) => $entry['status'] === 'REVERSED')
                    ->sum(fn (array $entry) => (float) $entry['row']->commission_amount), 2),
                'released' => round((float) $effectiveRows
                    ->filter(fn (array $entry) => in_array($entry['display_status'], ['APPROVED', 'PAID'], true))
                    ->sum(fn (array $entry) => (float) $entry['row']->commission_amount), 2),
                'reversed' => round(abs((float) $effectiveRows
                    ->filter(fn (array $entry) => $entry['status'] === 'REVERSED')
                    ->reject(fn (array $entry) => in_array((int) ($entry['row']->source_transaction_action_request_id ?? 0), $reversalRequestIds, true))
                    ->sum(fn (array $entry) => (float) $entry['row']->commission_amount)), 2),
            ],
            'rules' => CommissionRule::query()
                ->where('business_id', $actor->business_id)
                ->orderBy('rule_name')
                ->get(['id', 'code', 'rule_name'])
                ->map(fn (CommissionRule $rule) => ['id' => $rule->id, 'code' => $rule->code, 'name' => $rule->rule_name])
                ->values(),
            'personnel' => Personnel::query()
                ->where('business_id', $actor->business_id)
                ->where('commission_eligible', true)
                ->orderBy('last_name')->orderBy('first_name')
                ->get()
                ->map(fn (Personnel $row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->full_name])
                ->values(),
            'filters' => ['from' => $from, 'to' => $to, 'personnel_id' => $personnelId, 'status' => $status ?: 'ALL', 'booking_code' => $bookingCode, 'rule_id' => $ruleId],
        ];
    }

    public function serialize(CommissionEarning $row): array
    {
        $row->loadMissing(['personnel.position', 'service', 'rule', 'sale.booking', 'saleItem']);

        return [
            'id' => $row->id,
            'code' => $row->code,
            'work_date' => $row->work_date?->toDateString(),
            'personnel' => $row->personnel ? [
                'id' => $row->personnel->id,
                'code' => $row->personnel->code,
                'name' => $row->personnel->full_name,
                'position' => $row->personnel->position?->display_value,
            ] : null,
            'earning_type' => $row->earning_type ?: ($row->visit_retail_line_id ? 'RETAIL' : 'SERVICE'),
            'service' => ['id' => $row->service_id, 'name' => $row->service_name_snapshot],
            'item' => ['type' => $row->earning_type ?: ($row->visit_retail_line_id ? 'RETAIL' : 'SERVICE'), 'name' => $row->service_name_snapshot],
            'booking_code' => $row->sale?->booking?->code,
            'rule' => $row->rule ? ['id' => $row->rule->id, 'code' => $row->rule->code, 'name' => $row->rule->rule_name] : null,
            'basis' => $row->basis,
            'basis_amount' => (float) $row->basis_amount,
            'calculation_type' => $row->calculation_type,
            'rate_value' => (float) $row->rate_value,
            'commission_amount' => $this->displayStatus($row) === 'REVERSED'
                ? -abs((float) $row->commission_amount)
                : (float) $row->commission_amount,
            'status' => $this->displayStatus($row),
            'source_status' => $row->status,
            'reversal_reason' => $this->displayStatus($row) === 'REVERSED'
                ? ($row->reversal_reason ?: match (true) {
                    strtoupper((string) $row->sale?->status) === 'REVERSED' => 'Source transaction was reversed.',
                    $this->sourceLineIsReversed($row) => 'Source transaction line was removed or reversed by an approved action.',
                    $this->isNegativeTransactionAdjustment($row) => 'Commission reversal adjustment posted by an approved transaction action.',
                    default => null,
                })
                : $row->reversal_reason,
        ];
    }

    private function effectiveStatus(CommissionEarning $row): string
    {
        if (strtoupper((string) $row->sale?->status) === 'REVERSED') {
            return 'REVERSED';
        }

        if ($this->sourceLineIsReversed($row)) {
            return 'REVERSED';
        }

        return strtoupper((string) $row->status);
    }

    private function displayStatus(CommissionEarning $row): string
    {
        if ($this->effectiveStatus($row) === 'REVERSED' || $this->isNegativeTransactionAdjustment($row)) {
            return 'REVERSED';
        }

        return strtoupper((string) $row->status);
    }

    private function isNegativeTransactionAdjustment(CommissionEarning $row): bool
    {
        return strtoupper((string) $row->earning_type) === 'ADJUSTMENT'
            && (float) $row->commission_amount < 0
            && $row->source_transaction_action_request_id !== null;
    }

    private function sourceLineIsReversed(CommissionEarning $row): bool
    {
        // Transaction-action adjustment entries must remain OPEN/APPROVED/PAID as
        // applicable because they are the auditable counter-entry applied to a
        // later payroll. Only the original source earning is displayed as reversed.
        if (strtoupper((string) $row->earning_type) === 'ADJUSTMENT') {
            return false;
        }

        $status = strtoupper((string) ($row->saleItem?->correction_status ?? 'ACTIVE'));
        return in_array($status, ['REMOVED', 'REVERSED'], true);
    }


    /**
     * Removes only presentation-level duplicate correction families created by the
     * historical sequence "positive correction adjustment -> canonical checkout earning
     * -> balancing negative adjustment". Ledger rows remain untouched for audit.
     *
     * A family is suppressed only when a later canonical SERVICE/RETAIL earning exists,
     * all still-active family rows are pre-approval (OPEN / IN_PAYROLL_DRAFT), and removing
     * the family leaves the exact commission currently expected for that Sale Item.
     */
    public function withoutRedundantCorrectionDuplicates(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $suppressIds = collect();
        $pairKeys = $rows
            ->filter(fn (CommissionEarning $row) => $row->sale_item_id && $row->personnel_id)
            ->map(fn (CommissionEarning $row) => (int) $row->sale_item_id.'|'.(int) $row->personnel_id)
            ->unique()
            ->values();

        if ($pairKeys->isEmpty()) {
            return $rows;
        }

        // Load candidates from the ledger rather than only from the already-filtered
        // result set. This keeps ALL / OPEN / REVERSED summary filters consistent.
        $saleItemIds = $rows->pluck('sale_item_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $candidates = CommissionEarning::query()
            ->whereIn('sale_item_id', $saleItemIds->all())
            ->where('earning_type', 'ADJUSTMENT')
            ->where('commission_amount', '>', self::EPSILON)
            ->whereNotNull('source_transaction_action_request_id')
            ->where('status', '<>', 'REVERSED')
            ->get()
            ->filter(fn (CommissionEarning $row) => $pairKeys->contains((int) $row->sale_item_id.'|'.(int) $row->personnel_id));

        foreach ($candidates as $positive) {
            $item = SaleItem::query()->find($positive->sale_item_id);
            $sale = $item ? Sale::query()->find($item->sale_id) : null;
            if (! $item || ! $sale) {
                continue;
            }

            $canonical = CommissionEarning::query()
                ->where('sale_item_id', $item->id)
                ->where('personnel_id', $positive->personnel_id)
                ->whereIn('earning_type', ['SERVICE', 'RETAIL'])
                ->where('status', '<>', 'REVERSED')
                ->where('created_at', '>=', $positive->created_at)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if (! $canonical) {
                continue;
            }

            $expected = $this->expectedForSaleItem($sale, $item);
            if (! $expected || (int) $expected['personnel']->id !== (int) $positive->personnel_id) {
                continue;
            }

            $group = CommissionEarning::query()
                ->where('sale_item_id', $item->id)
                ->where('personnel_id', $positive->personnel_id)
                ->where('earning_type', '<>', 'INCENTIVE')
                ->where('status', '<>', 'REVERSED')
                ->get();

            $family = $group->filter(fn (CommissionEarning $row) =>
                (int) $row->id === (int) $positive->id
                || (int) ($row->source_commission_earning_id ?? 0) === (int) $positive->id
            );

            if ($family->isEmpty()) {
                continue;
            }

            $familyStatuses = $family->map(fn (CommissionEarning $row) => strtoupper((string) $row->status))->unique();
            if ($familyStatuses->diff(['OPEN', 'IN_PAYROLL_DRAFT'])->isNotEmpty()) {
                // Locked historical payroll effects must remain visible and require an
                // explicit recovery/correction workflow rather than UI suppression.
                continue;
            }

            $currentTotal = round((float) $group->sum('commission_amount'), 2);
            $familyNet = round((float) $family->sum('commission_amount'), 2);
            $target = round((float) $expected['commission_amount'], 2);

            if (abs(($currentTotal - $familyNet) - $target) <= self::EPSILON) {
                $suppressIds = $suppressIds->merge($family->pluck('id'));
            }
        }

        if ($suppressIds->isEmpty()) {
            return $rows;
        }

        $ids = $suppressIds->map(fn ($id) => (int) $id)->unique();

        return $rows->reject(fn (CommissionEarning $row) => $ids->contains((int) $row->id))->values();
    }

    private function economicCommissionAmountForItemPersonnel(SaleItem $item, int $personnelId): float
    {
        return round((float) CommissionEarning::query()
            ->where('sale_item_id', $item->id)
            ->where('personnel_id', $personnelId)
            ->where('status', '<>', 'REVERSED')
            ->where(function ($query) {
                $query->whereNull('earning_type')->orWhere('earning_type', '<>', 'INCENTIVE');
            })
            ->sum('commission_amount'), 2);
    }

    /**
     * Returns the commission that should economically remain on a Sale Item after
     * an approved transaction Correction/Refund. The rule is resolved using the
     * original service/sale date while the amount uses the corrected line state.
     */
    public function expectedForSaleItem(Sale $sale, SaleItem $item): ?array
    {
        $item->loadMissing([
            'service.category', 'assignedPersonnel.position', 'retailProduct',
            'visitServiceLine.packageEnrollment', 'packageEnrollment',
        ]);

        if (in_array(strtoupper((string) ($item->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true)) {
            return null;
        }

        $personnel = $item->assignedPersonnel;
        if (! $personnel || ! $personnel->commission_eligible) {
            return null;
        }

        $type = strtoupper((string) $item->item_type);
        $effectiveNet = round(max(0, (float) ($item->effective_net_line_total ?? $item->net_line_total ?? $item->line_total ?? 0)), 2);
        $currentNetBeforeRefund = round(max(0, (float) ($item->net_line_total ?? $item->line_total ?? 0)), 2);
        $refundFactor = $currentNetBeforeRefund > 0.009
            ? max(0, min(1, $effectiveNet / $currentNetBeforeRefund))
            : 1.0;

        if ($type === 'PRODUCT') {
            $asOf = CarbonImmutable::parse($sale->financial_finalized_at ?: $sale->sale_date)
                ->setTimezone($this->clock->timezone());
            $rule = $this->rules->resolveRetailFor(
                (int) $sale->business_id,
                $personnel,
                $asOf,
                $sale->branch_id,
                $item->retailProduct,
            );
            if (! $rule) return null;

            $units = max(1, (int) $item->quantity);
            $commission = $rule->calculation_type === 'FIXED'
                ? round((float) $rule->rate_value * $units * $refundFactor, 2)
                : round($effectiveNet * ((float) $rule->rate_value / 100), 2);

            return [
                'personnel' => $personnel,
                'rule' => $rule,
                'work_date' => $asOf->toDateString(),
                'basis' => 'NET_RETAIL_SALE',
                'basis_amount' => $effectiveNet,
                'calculation_type' => $rule->calculation_type,
                'rate_value' => (float) $rule->rate_value,
                'commission_amount' => $commission,
                'service_id' => null,
                'service_name' => $item->item_name_snapshot,
            ];
        }

        if (! in_array($type, ['SERVICE', 'PACKAGE', 'SERVICE_CREDIT_USE'], true) || ! $item->service) {
            return null;
        }

        $completedAt = $item->visitServiceLine?->completed_at ?: $sale->sale_date ?: $sale->financial_finalized_at;
        $asOf = CarbonImmutable::parse($completedAt)->setTimezone($this->clock->timezone());
        $rule = $this->rules->resolveFor(
            (int) $sale->business_id,
            $personnel,
            $item->service,
            $asOf,
            $sale->branch_id,
        );
        if (! $rule) return null;

        [$basisAmount, $basisLabel, $units] = $this->commissionBasisForItem(
            $item,
            (int) $sale->business_id,
            (int) $item->service->id,
            $asOf->toDateString(),
            $sale->branch_id,
        );

        $commission = $rule->calculation_type === 'FIXED'
            ? round((float) $rule->rate_value * $units * $refundFactor, 2)
            : round($basisAmount * $refundFactor * ((float) $rule->rate_value / 100), 2);

        return [
            'personnel' => $personnel,
            'rule' => $rule,
            'work_date' => $asOf->toDateString(),
            'basis' => $basisLabel,
            'basis_amount' => round($basisAmount * $refundFactor, 2),
            'calculation_type' => $rule->calculation_type,
            'rate_value' => (float) $rule->rate_value,
            'commission_amount' => $commission,
            'service_id' => $item->service_id,
            'service_name' => $item->item_name_snapshot ?: $item->service->name,
        ];
    }

    /**
     * Commission is earned on the rendered service value, never on the act of
     * selling a multi-session package. A PACKAGE Sale Item reaches this method
     * only when it is tied to a completed Visit Service, which represents the
     * first rendered package session. Later package sessions arrive as
     * SERVICE_CREDIT_USE items.
     */
    private function commissionBasisForItem(
        SaleItem $item,
        int $businessId,
        int $serviceId,
        string $date,
        ?int $branchId,
    ): array {
        $type = strtoupper((string) $item->item_type);
        $units = max(1, (int) $item->quantity);

        if (in_array($type, ['PACKAGE', 'SERVICE_CREDIT_USE'], true)) {
            $enrollment = $item->packageEnrollment ?: $item->visitServiceLine?->packageEnrollment;
            $sessionCount = max(
                1,
                (int) ($enrollment?->total_sessions ?: $item->service?->number_of_sessions ?: 1),
            );

            $packageGross = round((float) ($enrollment?->package_gross_price_snapshot ?? 0), 2);
            if ($packageGross <= 0) {
                $packageGross = $type === 'PACKAGE'
                    ? round((float) ($item->line_total ?? 0), 2)
                    : $this->standardServicePrice($businessId, $serviceId, $date, $branchId);
            }

            $sessionValue = round($packageGross / $sessionCount, 2);

            return [
                round($sessionValue * $units, 2),
                'RENDERED_PACKAGE_SESSION_VALUE',
                $units,
            ];
        }

        return [
            round($this->standardServicePrice($businessId, $serviceId, $date, $branchId) * $units, 2),
            'STANDARD_SERVICE_PRICE',
            $units,
        ];
    }

    private function standardServicePrice(int $businessId, int $serviceId, string $date, ?int $branchId): float
    {
        $rows = ServicePrice::query()
            ->where('business_id', $businessId)
            ->where('service_id', $serviceId)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $date);
            })
            ->where(function ($query) use ($branchId) {
                $query->where('scope', 'ALL');
                if ($branchId) {
                    $query->orWhere(function ($branch) use ($branchId) {
                        $branch->where('scope', 'BRANCH')->where('branch_id', $branchId);
                    });
                }
            })
            ->orderByDesc('effective_from')
            ->get();

        $price = $rows->first(fn (ServicePrice $row) => $branchId && $row->scope === 'BRANCH' && (int) $row->branch_id === (int) $branchId)
            ?: $rows->first(fn (ServicePrice $row) => $row->scope === 'ALL');

        return round((float) ($price?->standard_price ?? 0), 2);
    }

    private function snapshot(CommissionEarning $earning): array
    {
        return [
            'id' => $earning->id,
            'code' => $earning->code,
            'sale_id' => $earning->sale_id,
            'sale_item_id' => $earning->sale_item_id,
            'visit_service_line_id' => $earning->visit_service_line_id,
            'visit_retail_line_id' => $earning->visit_retail_line_id,
            'earning_type' => $earning->earning_type,
            'personnel_id' => $earning->personnel_id,
            'service_id' => $earning->service_id,
            'commission_rule_id' => $earning->commission_rule_id,
            'basis_amount' => (float) $earning->basis_amount,
            'commission_amount' => (float) $earning->commission_amount,
            'status' => $earning->status,
        ];
    }

    private function date(?string $value, string $fallback): string
    {
        try {
            return $value ? CarbonImmutable::parse($value, $this->clock->timezone())->toDateString() : $fallback;
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
