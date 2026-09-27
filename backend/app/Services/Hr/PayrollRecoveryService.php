<?php

namespace App\Services\Hr;

use App\Models\CommissionEarning;
use App\Models\PayrollLine;
use App\Models\PayrollRecovery;
use App\Models\PayrollRecoveryAllocation;
use App\Models\PayrollRun;
use App\Models\TransactionActionRequest;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PayrollRecoveryService
{
    private const EPSILON = 0.009;

    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {}

    public function sourceIsPayrollLocked(?CommissionEarning $source): bool
    {
        if (! $source) return false;
        return in_array(strtoupper((string) $source->status), ['APPROVED', 'PAID'], true)
            || ($source->payroll_run_id && $source->payroll_line_id && in_array(strtoupper((string) $source->status), ['APPROVED', 'PAID'], true));
    }

    public function createForLockedAdjustment(
        User $actor,
        CommissionEarning $adjustment,
        ?CommissionEarning $source,
        string $recoveryType,
        ?TransactionActionRequest $request = null,
    ): ?PayrollRecovery {
        $amount = round(abs((float) $adjustment->commission_amount), 2);
        if ($amount <= self::EPSILON || (float) $adjustment->commission_amount >= 0) return null;
        if (! $this->sourceIsPayrollLocked($source)) return null;

        $existing = PayrollRecovery::query()
            ->where('source_adjustment_earning_id', $adjustment->id)
            ->first();
        if ($existing) return $existing;

        $now = $this->clock->now();
        $type = strtoupper($recoveryType) === 'INCENTIVE' ? 'INCENTIVE' : 'COMMISSION';
        $sourceLabel = trim((string) ($adjustment->service_name_snapshot ?: $source?->service_name_snapshot ?: $type));
        $reason = trim((string) ($request?->reason_label ?: $adjustment->reversal_reason ?: 'Post-payroll transaction correction/reversal'));

        $recovery = PayrollRecovery::query()->create([
            'business_id' => $adjustment->business_id,
            'code' => $this->codes->next('PAYROLL_RECOVERY:'.$now->format('my'), 'PYR-'.$now->format('my').'-', 6, $adjustment->business_id),
            'personnel_id' => $adjustment->personnel_id,
            'recovery_type' => $type,
            'source_commission_earning_id' => $source?->id,
            'source_adjustment_earning_id' => $adjustment->id,
            'source_transaction_action_request_id' => $request?->id ?: $adjustment->source_transaction_action_request_id,
            'original_payroll_run_id' => $source?->payroll_run_id,
            'original_payroll_line_id' => $source?->payroll_line_id,
            'origin_work_date' => $source?->work_date?->toDateString() ?: $adjustment->work_date?->toDateString(),
            'source_label' => $sourceLabel ?: null,
            'recovery_amount' => $amount,
            'applied_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'OPEN',
            'reason' => $reason ?: null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(
            action: 'CREATE_PAYROLL_RECOVERY',
            module: 'payroll',
            entityType: PayrollRecovery::class,
            entityId: $recovery->id,
            after: $this->snapshot($recovery),
            reason: $reason ?: null,
            actor: $actor,
        );

        return $recovery;
    }

    public function eligibleQuery(PayrollRun $run): Builder
    {
        return PayrollRecovery::query()
            ->where('business_id', $run->business_id)
            ->whereIn('status', ['OPEN', 'PARTIAL'])
            ->where('remaining_amount', '>', self::EPSILON)
            ->when($run->branch_id, fn ($query) => $query->whereHas('personnel', fn ($personnel) => $personnel->where('branch_id', $run->branch_id)));
    }

    public function eligibleForPersonnel(PayrollRun $run, int $personnelId): Collection
    {
        return $this->eligibleQuery($run)
            ->where('personnel_id', $personnelId)
            ->with(['originalPayrollRun', 'sourceAction'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function reserveForLine(User $actor, PayrollRun $run, PayrollLine $line, float $capacity): float
    {
        $capacity = round(max(0, $capacity), 2);
        if ($capacity <= self::EPSILON) return 0.0;

        $total = 0.0;
        foreach ($this->eligibleForPersonnel($run, (int) $line->personnel_id) as $candidate) {
            if ($capacity - $total <= self::EPSILON) break;

            /** @var PayrollRecovery $recovery */
            $recovery = PayrollRecovery::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
            if (! in_array(strtoupper((string) $recovery->status), ['OPEN', 'PARTIAL'], true)) continue;

            $reservedElsewhere = round((float) PayrollRecoveryAllocation::query()
                ->where('payroll_recovery_id', $recovery->id)
                ->where('status', 'RESERVED')
                ->where('payroll_run_id', '<>', $run->id)
                ->sum('amount'), 2);

            $available = round(max(0, (float) $recovery->remaining_amount - $reservedElsewhere), 2);
            if ($available <= self::EPSILON) continue;

            $amount = round(min($available, $capacity - $total), 2);
            if ($amount <= self::EPSILON) continue;

            PayrollRecoveryAllocation::query()->updateOrCreate(
                ['payroll_recovery_id' => $recovery->id, 'payroll_run_id' => $run->id],
                [
                    'payroll_line_id' => $line->id,
                    'amount' => $amount,
                    'status' => 'RESERVED',
                    'created_by' => $actor->id,
                    'applied_at' => null,
                ],
            );
            $total += $amount;
        }

        return round($total, 2);
    }

    public function applyRun(User $actor, PayrollRun $run): void
    {
        $allocations = PayrollRecoveryAllocation::query()
            ->where('payroll_run_id', $run->id)
            ->where('status', 'RESERVED')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $recovery = PayrollRecovery::query()->whereKey($allocation->payroll_recovery_id)->lockForUpdate()->firstOrFail();
            $amount = round((float) $allocation->amount, 2);
            if ($amount <= self::EPSILON) continue;

            $applied = round((float) $recovery->applied_amount + $amount, 2);
            $remaining = round(max(0, (float) $recovery->recovery_amount - $applied), 2);
            $recovery->update([
                'applied_amount' => $applied,
                'remaining_amount' => $remaining,
                'status' => $remaining <= self::EPSILON ? 'RECOVERED' : 'PARTIAL',
            ]);
            $allocation->update(['status' => 'APPLIED', 'applied_at' => $this->clock->now()]);

            $this->audit->record(
                action: 'APPLY_PAYROLL_RECOVERY',
                module: 'payroll',
                entityType: PayrollRecovery::class,
                entityId: $recovery->id,
                before: ['remaining_amount' => round($remaining + $amount, 2)],
                after: $this->snapshot($recovery->fresh()),
                reason: 'Applied through payroll '.$run->code,
                actor: $actor,
            );
        }
    }

    public function releaseRunReservations(PayrollRun $run): void
    {
        PayrollRecoveryAllocation::query()
            ->where('payroll_run_id', $run->id)
            ->where('status', 'RESERVED')
            ->delete();
    }

    public function createdAfterCalculation(PayrollRun $run): int
    {
        if (! $run->calculated_at) return 0;
        return $this->eligibleQuery($run)
            ->where('created_at', '>', $run->calculated_at)
            ->count();
    }

    private function snapshot(PayrollRecovery $recovery): array
    {
        return [
            'id' => $recovery->id,
            'code' => $recovery->code,
            'personnel_id' => $recovery->personnel_id,
            'recovery_type' => $recovery->recovery_type,
            'source_commission_earning_id' => $recovery->source_commission_earning_id,
            'source_adjustment_earning_id' => $recovery->source_adjustment_earning_id,
            'original_payroll_run_id' => $recovery->original_payroll_run_id,
            'recovery_amount' => (float) $recovery->recovery_amount,
            'applied_amount' => (float) $recovery->applied_amount,
            'remaining_amount' => (float) $recovery->remaining_amount,
            'status' => $recovery->status,
        ];
    }
}
