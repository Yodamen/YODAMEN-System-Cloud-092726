<?php

namespace App\Services\Incentives;

use App\Models\CommissionEarning;
use App\Models\IncentiveRule;
use App\Models\Personnel;
use App\Models\Sale;
use App\Models\TransactionActionRequest;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Hr\DailyCompensationService;
use App\Services\Hr\PayrollRecoveryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class IncentiveEarningService
{
    private const EPSILON = 0.009;

    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly DailyCompensationService $dailyCompensation,
        private readonly PayrollRecoveryService $payrollRecoveries,
    ) {}

    /**
     * Reconcile incentive earnings to the current finalized sale state.
     * Positive and negative differences are counter-posted as new earnings, so
     * approved/paid payroll history is never destructively rewritten.
     */
    public function reconcileSale(User $actor, Sale $sale, ?TransactionActionRequest $request = null): Collection
    {
        if ((int) $sale->business_id !== (int) $actor->business_id) abort(404);

        return DB::transaction(function () use ($actor, $sale, $request) {
            $sale = Sale::query()
                ->whereKey($sale->id)
                ->with(['items.assignedPersonnel', 'items.service'])
                ->lockForUpdate()
                ->firstOrFail();

            $financiallyValid = $sale->financial_finalized_at !== null
                && in_array(strtoupper((string) $sale->status), ['PAID', 'ON_ACCOUNT'], true);
            $workDate = ($sale->financial_finalized_at ?: $sale->sale_date ?: $this->clock->now())->toDateString();

            $expected = [];
            $personnelById = [];

            if ($financiallyValid) {
                $rules = IncentiveRule::query()
                    ->where('business_id', $sale->business_id)
                    ->where('status', 'ACTIVE')
                    ->whereDate('effective_from', '<=', $workDate)
                    ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', $workDate))
                    ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $sale->branch_id))
                    ->orderByDesc('priority')
                    ->orderBy('id')
                    ->get();

                $byPersonnel = $sale->items
                    ->filter(fn ($i) => $i->assigned_personnel_id && ! in_array(strtoupper((string) ($i->correction_status ?? 'ACTIVE')), ['REMOVED', 'REVERSED'], true))
                    ->groupBy('assigned_personnel_id');

                foreach ($byPersonnel as $personnelId => $items) {
                    /** @var Personnel|null $personnel */
                    $personnel = $items->first()->assignedPersonnel;
                    if (! $personnel || strtoupper((string) $personnel->status) !== 'ACTIVE') continue;
                    $personnelById[(int) $personnelId] = $personnel;

                    $eligible = $rules->map(function ($rule) use ($items, $personnelId) {
                        $personnelIds = $this->ruleIds($rule->personnel_ids, $rule->personnel_id);
                        if ($rule->target_scope === 'PERSONNEL' && ! in_array((int) $personnelId, $personnelIds, true)) return null;

                        $scopedItems = $this->itemsForRule($items, $rule);
                        $basis = $this->metricBasis($scopedItems, (string) $rule->metric);
                        if ($basis + self::EPSILON < (float) $rule->threshold_value) return null;

                        return ['rule' => $rule, 'basis' => $basis, 'items' => $scopedItems];
                    })->filter()->values();

                    $exclusive = $eligible->first(fn ($entry) => ! (bool) $entry['rule']->stackable);
                    if ($exclusive) $eligible = collect([$exclusive]);

                    foreach ($eligible as $entry) {
                        $rule = $entry['rule'];
                        $basis = max(0, (float) $entry['basis']);
                        $amount = $rule->reward_type === 'PERCENTAGE'
                            ? round($basis * ((float) $rule->reward_value / 100), 2)
                            : round((float) $rule->reward_value, 2);
                        if ($amount <= self::EPSILON) continue;

                        $expected[$this->pairKey((int) $personnelId, (int) $rule->id)] = [
                            'personnel' => $personnel,
                            'rule' => $rule,
                            'source_item' => $entry['items']->first() ?: $items->first(),
                            'basis' => $basis,
                            'amount' => $amount,
                        ];
                    }
                }
            }

            $existing = CommissionEarning::query()
                ->where('business_id', $sale->business_id)
                ->where('sale_id', $sale->id)
                ->where('earning_type', 'INCENTIVE')
                ->whereNotNull('incentive_rule_id')
                ->get();

            $existingPairs = $existing->groupBy(fn ($row) => $this->pairKey((int) $row->personnel_id, (int) $row->incentive_rule_id));
            $keys = collect(array_keys($expected))->merge($existingPairs->keys())->unique();
            $created = collect();
            $touched = [];

            foreach ($keys as $key) {
                $rows = $existingPairs->get($key, collect());
                $parts = explode('|', $key);
                $personnelId = (int) ($parts[0] ?? 0);
                $ruleId = (int) ($parts[1] ?? 0);
                $definition = $expected[$key] ?? null;
                $current = round((float) $rows->sum('commission_amount'), 2);
                $target = round((float) ($definition['amount'] ?? 0), 2);
                $delta = round($target - $current, 2);
                if (abs($delta) <= self::EPSILON) continue;

                $personnel = $definition['personnel'] ?? $personnelById[$personnelId] ?? Personnel::query()->where('business_id', $sale->business_id)->find($personnelId);
                $rule = $definition['rule'] ?? IncentiveRule::query()->find($ruleId);
                if (! $personnel || ! $rule) continue;

                $basis = (float) ($definition['basis'] ?? 0);
                $source = $rows->first(fn ($row) => (float) $row->commission_amount > 0) ?: $rows->first();
                $lockedSource = $rows->first(fn ($row) => in_array(strtoupper((string) $row->status), ['APPROVED', 'PAID'], true) && (float) $row->commission_amount > 0);
                $sourceItem = $definition['source_item'] ?? null;
                if (! $sourceItem && $source) $sourceItem = $sale->items->firstWhere('id', $source->sale_item_id);
                if (! $sourceItem) continue;

                $effectBase = $request
                    ? 'INCENTIVE_RECON:TXA:'.$request->id.':'.$sale->id.':'.$ruleId.':'.$personnelId
                    : 'INCENTIVE_RECON:'.$sale->id.':'.$ruleId.':'.$personnelId.':'.number_format($target, 2, '.', '');
                $createEarning = function(float $amount, string $status, string $date, string $suffix, ?CommissionEarning $sourceRow) use ($actor, $sale, $sourceItem, $personnelId, $personnel, $ruleId, $rule, $basis, $request, $effectBase) {
                    $effectKey = $effectBase.':'.$suffix;
                    $existing = CommissionEarning::query()->where('transaction_action_effect_key', $effectKey)->first();
                    if ($existing) return $existing;
                    $now = $this->clock->now();
                    return CommissionEarning::query()->create([
                        'business_id' => $sale->business_id,
                        'code' => $this->codes->next('COMMISSION_EARNING:'.$now->format('my'), 'CME-'.$now->format('my').'-', 8, $sale->business_id),
                        'sale_id' => $sale->id,
                        'sale_item_id' => $sourceItem->id,
                        'visit_id' => $sale->visit_id,
                        'visit_service_line_id' => $sourceItem->visit_service_line_id,
                        'service_id' => $sourceItem->service_id,
                        'service_name_snapshot' => 'Incentive: '.$rule->rule_name,
                        'earning_type' => 'INCENTIVE',
                        'booking_id' => $sale->booking_id,
                        'customer_id' => $sale->customer_id,
                        'personnel_id' => $personnelId,
                        'incentive_rule_id' => $ruleId,
                        'work_date' => $date,
                        'personnel_name_snapshot' => $personnel->full_name,
                        'basis' => 'INCENTIVE_'.$rule->metric,
                        'basis_amount' => $basis,
                        'calculation_type' => 'ADJUSTMENT',
                        'rate_value' => $rule->reward_value,
                        'commission_amount' => round($amount, 2),
                        'status' => $status,
                        'source_transaction_action_request_id' => $request?->id,
                        'source_commission_earning_id' => $sourceRow?->id,
                        'transaction_action_effect_key' => $effectKey,
                        'reversal_reason' => $amount < 0 ? ($request?->reason_label ?: 'Incentive correction/reversal') : null,
                        'created_by' => $actor->id,
                    ]);
                };

                if ($delta > 0) {
                    // Once an earning has reached Approved/Paid payroll, later additions belong
                    // to a future payroll rather than reopening the historical payroll period.
                    $date = $lockedSource ? $this->clock->today() : $workDate;
                    $earning = $createEarning($delta, 'OPEN', $date, 'ADD', $source);
                    $created->push($earning);
                    $touched[$personnelId.'|'.$date] = [$personnel, $date];
                    continue;
                }

                $reduction = abs($delta);
                $unlockedNet = round((float) $rows->filter(fn ($row) => in_array(strtoupper((string) $row->status), ['OPEN', 'IN_PAYROLL_DRAFT'], true))->sum('commission_amount'), 2);
                $preAmount = min($reduction, max(0, $unlockedNet));
                if ($preAmount > self::EPSILON) {
                    $earning = $createEarning(-$preAmount, 'OPEN', $workDate, 'PRE', $source);
                    $created->push($earning);
                    $touched[$personnelId.'|'.$workDate] = [$personnel, $workDate];
                }

                $remaining = round($reduction - $preAmount, 2);
                if ($remaining <= self::EPSILON) continue;

                if ($lockedSource) {
                    $today = $this->clock->today();
                    $earning = $createEarning(-$remaining, 'RECOVERY_PENDING', $today, 'RECOVERY', $lockedSource);
                    $this->payrollRecoveries->createForLockedAdjustment($actor, $earning, $lockedSource, 'INCENTIVE', $request);
                    $created->push($earning);
                    continue;
                }

                $earning = $createEarning(-$remaining, 'OPEN', $workDate, 'PRE_REMAINDER', $source);
                $created->push($earning);
                $touched[$personnelId.'|'.$workDate] = [$personnel, $workDate];
            }

            foreach ($touched as [$personnel, $date]) {
                $this->dailyCompensation->recalculatePersonnelDay($personnel, $date);
            }

            return $created;
        });
    }

    private function itemsForRule(Collection $items, IncentiveRule $rule): Collection
    {
        $serviceIds = $this->ruleIds($rule->service_ids);
        $categoryIds = $this->ruleIds($rule->service_category_reference_ids);
        if (empty($serviceIds) && empty($categoryIds)) return $items;

        return $items->filter(function ($item) use ($serviceIds, $categoryIds) {
            if (! in_array(strtoupper((string) $item->item_type), ['SERVICE', 'PACKAGE'], true)) return false;
            $serviceId = (int) ($item->service_id ?? 0);
            $categoryId = (int) ($item->service?->service_category_reference_id ?? 0);
            return in_array($serviceId, $serviceIds, true) || ($categoryId && in_array($categoryId, $categoryIds, true));
        })->values();
    }

    private function metricBasis(Collection $items, string $metric): float
    {
        return match (strtoupper($metric)) {
            'SERVICE_NET' => round((float) $items->filter(fn ($i) => in_array(strtoupper((string) $i->item_type), ['SERVICE','PACKAGE'], true))->sum(fn ($i) => max(0, (float) ($i->effective_net_line_total ?? $i->net_line_total ?? 0))), 2),
            'RETAIL_NET' => round((float) $items->filter(fn ($i) => strtoupper((string) $i->item_type) === 'PRODUCT')->sum(fn ($i) => max(0, (float) ($i->effective_net_line_total ?? $i->net_line_total ?? 0))), 2),
            'ITEM_COUNT' => round((float) $items->sum(fn ($i) => (float) $i->quantity), 2),
            default => round((float) $items->sum(fn ($i) => max(0, (float) ($i->effective_net_line_total ?? $i->net_line_total ?? 0))), 2),
        };
    }

    private function ruleIds(?array $values, mixed $legacyValue = null): array
    {
        $ids = collect($values ?: [])->map(fn ($v) => (int) $v)->filter()->unique()->values()->all();
        if (empty($ids) && $legacyValue) $ids = [(int) $legacyValue];
        return $ids;
    }

    private function pairKey(int $personnelId, int $ruleId): string
    {
        return $personnelId.'|'.$ruleId;
    }
}
