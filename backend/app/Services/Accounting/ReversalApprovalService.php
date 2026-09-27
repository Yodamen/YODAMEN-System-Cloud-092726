<?php

namespace App\Services\Accounting;

use App\Models\BusinessFundingTransaction;
use App\Models\CashDeposit;
use App\Models\CashRemittance;
use App\Models\PayrollAdjustment;
use App\Models\PettyCashTransaction;
use App\Models\ReversalApprovalRequest;
use App\Models\User;
use App\Services\Foundation\BusinessClock;
use App\Services\Foundation\CodeSequenceService;
use App\Services\Security\AuditService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReversalApprovalService
{
    public function __construct(
        private readonly CodeSequenceService $codes,
        private readonly BusinessClock $clock,
        private readonly AuditService $audit,
    ) {}

    public function request(User $actor, string $module, string $sourceType, int $sourceId, ?string $sourceCode, string $reason, ?int $reasonReferenceId = null): ReversalApprovalRequest
    {
        $reason = trim($reason);
        $sourceType = strtoupper(trim($sourceType));
        if ($reason === '') throw new DomainException('Reversal reason is required.');

        return DB::transaction(function () use ($actor, $module, $sourceType, $sourceId, $sourceCode, $reason, $reasonReferenceId) {
            $this->lockSource($actor, $sourceType, $sourceId);

            if (ReversalApprovalRequest::query()
                ->where('business_id', $actor->business_id)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('status', 'PENDING')
                ->exists()) {
                throw new DomainException('This record already has a pending reversal request. Approve or reject it before creating another request.');
            }

            $now = $this->clock->now();
            $row = ReversalApprovalRequest::query()->create([
                'business_id'=>$actor->business_id,
                'code'=>$this->codes->next('REVERSAL_APPROVAL:'.$now->format('my'),'RVR-'.$now->format('my').'-',6,$actor->business_id),
                'module'=>$module,'source_type'=>$sourceType,'source_id'=>$sourceId,'source_code'=>$sourceCode,
                'status'=>'PENDING','reason'=>$reason,'reason_reference_id'=>$reasonReferenceId,'requested_by'=>$actor->id,'requested_at'=>$now,
            ]);
            $this->audit->record(action:'REQUEST_REVERSAL_APPROVAL', module:$module, entityType:ReversalApprovalRequest::class, entityId:$row->id, after:$row->toArray(), reason:$reason, actor:$actor);
            return $row->fresh(['requester','reviewer','reasonReference','reviewReasonReference']);
        });
    }

    public function approve(User $actor, ReversalApprovalRequest $request, string $expectedSourceType, callable $apply, ?string $notes = null): array
    {
        if ((int)$request->business_id !== (int)$actor->business_id) abort(404);
        return DB::transaction(function () use ($actor,$request,$expectedSourceType,$apply,$notes) {
            $locked = ReversalApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->source_type !== $expectedSourceType) abort(404);
            if ($locked->status !== 'PENDING') throw new DomainException('Only Pending reversal requests can be approved.');
            $result = $apply($locked);
            $locked->update(['status'=>'APPROVED','reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_notes'=>$notes]);
            $this->audit->record(action:'APPROVE_REVERSAL_REQUEST', module:$locked->module, entityType:ReversalApprovalRequest::class, entityId:$locked->id, before:['status'=>'PENDING'], after:['status'=>'APPROVED'], reason:$notes ?: $locked->reason, actor:$actor);
            return ['request'=>$locked->fresh(['requester','reviewer','reasonReference','reviewReasonReference']),'result'=>$result];
        });
    }

    public function reject(User $actor, ReversalApprovalRequest $request, string $expectedSourceType, string $notes, ?int $reviewReasonReferenceId = null): ReversalApprovalRequest
    {
        if ((int)$request->business_id !== (int)$actor->business_id) abort(404);
        $notes = trim($notes);
        if ($notes === '') throw new DomainException('Rejection reason is required.');
        return DB::transaction(function () use ($actor, $request, $expectedSourceType, $notes, $reviewReasonReferenceId) {
            $locked = ReversalApprovalRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->source_type !== $expectedSourceType) abort(404);
            if ($locked->status !== 'PENDING') throw new DomainException('Only Pending reversal requests can be rejected.');
            $locked->update(['status'=>'REJECTED','reviewed_by'=>$actor->id,'reviewed_at'=>$this->clock->now(),'review_reason_reference_id'=>$reviewReasonReferenceId,'review_notes'=>$notes]);
            $this->audit->record(action:'REJECT_REVERSAL_REQUEST', module:$locked->module, entityType:ReversalApprovalRequest::class, entityId:$locked->id, before:['status'=>'PENDING'], after:['status'=>'REJECTED'], reason:$notes, actor:$actor);
            return $locked->fresh(['requester','reviewer','reasonReference','reviewReasonReference']);
        });
    }

    public static function serialize(?ReversalApprovalRequest $row): ?array
    {
        if (! $row) return null;
        $row->loadMissing(['reasonReference','reviewReasonReference']);
        return [
            'id'=>$row->id,'code'=>$row->code,'status'=>$row->status,'reason'=>$row->reason,
            'reason_category'=>$row->reasonReference?['id'=>(int)$row->reasonReference->id,'code'=>$row->reasonReference->code,'name'=>$row->reasonReference->display_value]:null,
            'review_reason_category'=>$row->reviewReasonReference?['id'=>(int)$row->reviewReasonReference->id,'code'=>$row->reviewReasonReference->code,'name'=>$row->reviewReasonReference->display_value]:null,
            'source_type'=>$row->source_type,'source_id'=>(int)$row->source_id,'source_code'=>$row->source_code,
            'requested_by'=>$row->requester?->display_name ?: $row->requester?->name,
            'requested_at'=>$row->requested_at?->toIso8601String(),
            'reviewed_by'=>$row->reviewer?->display_name ?: $row->reviewer?->name,
            'reviewed_at'=>$row->reviewed_at?->toIso8601String(),'review_notes'=>$row->review_notes,
        ];
    }

    private function lockSource(User $actor, string $sourceType, int $sourceId): Model
    {
        $modelClass = match (strtoupper($sourceType)) {
            'PETTY_CASH' => PettyCashTransaction::class,
            'BUSINESS_FUNDING' => BusinessFundingTransaction::class,
            'CASH_DEPOSIT' => CashDeposit::class,
            'CASH_REMITTANCE' => CashRemittance::class,
            'PAYROLL_ADJUSTMENT' => PayrollAdjustment::class,
            default => throw new DomainException('Unsupported reversal approval source.'),
        };

        /** @var Model|null $source */
        $source = $modelClass::query()
            ->where('business_id', $actor->business_id)
            ->whereKey($sourceId)
            ->lockForUpdate()
            ->first();

        if (! $source) abort(404);
        return $source;
    }
}
