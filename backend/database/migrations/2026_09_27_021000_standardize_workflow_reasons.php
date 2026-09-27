<?php

use App\Services\MasterData\WorkflowReasonCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            $groupId = DB::table('reference_groups')
                ->where('business_id', $businessId)
                ->where('code', WorkflowReasonCatalog::GROUP_CODE)
                ->value('id');

            if (! $groupId) {
                $groupId = DB::table('reference_groups')->insertGetId([
                    'business_id' => $businessId,
                    'code' => WorkflowReasonCatalog::GROUP_CODE,
                    'name' => 'Workflow Reason',
                    'description' => 'Standardized reason categories used by controlled approval, correction, refund and reversal workflows.',
                    'is_system' => true,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (WorkflowReasonCatalog::defaults() as $index => [$code, $displayValue, $contexts]) {
                DB::table('reference_values')->updateOrInsert(
                    ['reference_group_id' => $groupId, 'code' => $code],
                    [
                        'display_value' => $displayValue,
                        'sort_order' => ($index + 1) * 10,
                        'is_system' => true,
                        'active' => true,
                        'metadata' => json_encode(['contexts' => array_values($contexts)]),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }

        $this->addReasonColumns('inventory_adjustment_requests', 'iar');
        $this->addReasonColumns('inventory_receipt_action_requests', 'irar');
        $this->addReasonColumns('inventory_stock_returns', 'isr');
        $this->addReasonColumns('expense_reversal_requests', 'err');
        $this->addReasonColumns('reversal_approval_requests', 'rar');
        $this->addReasonColumns('transaction_action_requests', 'tar');

        if (Schema::hasTable('leave_requests') && ! Schema::hasColumn('leave_requests', 'review_reason_reference_id')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->unsignedBigInteger('review_reason_reference_id')->nullable()->after('reviewed_at');
                $table->index('review_reason_reference_id', 'leave_review_reason_idx');
                $table->foreign('review_reason_reference_id', 'leave_review_reason_fk')
                    ->references('id')->on('reference_values')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        // Backfill existing Sales standardized reason IDs while preserving legacy reason_code/reason_label fields.
        if (Schema::hasTable('transaction_action_requests') && Schema::hasColumn('transaction_action_requests', 'reason_reference_id')) {
            $rows = DB::table('transaction_action_requests')->whereNull('reason_reference_id')->get(['id', 'business_id', 'reason_code']);
            foreach ($rows as $row) {
                $referenceId = DB::table('reference_values as rv')
                    ->join('reference_groups as rg', 'rg.id', '=', 'rv.reference_group_id')
                    ->where('rg.business_id', $row->business_id)
                    ->where('rg.code', WorkflowReasonCatalog::GROUP_CODE)
                    ->where('rv.code', strtoupper((string) $row->reason_code))
                    ->value('rv.id');
                if ($referenceId) {
                    DB::table('transaction_action_requests')->where('id', $row->id)->update(['reason_reference_id' => $referenceId]);
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_requests') && Schema::hasColumn('leave_requests', 'review_reason_reference_id')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->dropForeign('leave_review_reason_fk');
                $table->dropIndex('leave_review_reason_idx');
                $table->dropColumn('review_reason_reference_id');
            });
        }

        $this->dropReasonColumns('transaction_action_requests', 'tar');
        $this->dropReasonColumns('reversal_approval_requests', 'rar');
        $this->dropReasonColumns('expense_reversal_requests', 'err');
        $this->dropReasonColumns('inventory_stock_returns', 'isr');
        $this->dropReasonColumns('inventory_receipt_action_requests', 'irar');
        $this->dropReasonColumns('inventory_adjustment_requests', 'iar');

        if (Schema::hasTable('reference_groups')) {
            $groupIds = DB::table('reference_groups')->where('code', WorkflowReasonCatalog::GROUP_CODE)->pluck('id');
            if ($groupIds->isNotEmpty()) {
                DB::table('reference_values')->whereIn('reference_group_id', $groupIds)->delete();
                DB::table('reference_groups')->whereIn('id', $groupIds)->delete();
            }
        }
    }

    private function addReasonColumns(string $tableName, string $prefix): void
    {
        if (! Schema::hasTable($tableName)) return;

        if (! Schema::hasColumn($tableName, 'reason_reference_id')) {
            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->unsignedBigInteger('reason_reference_id')->nullable();
                $table->index('reason_reference_id', $prefix.'_reason_idx');
                $table->foreign('reason_reference_id', $prefix.'_reason_fk')
                    ->references('id')->on('reference_values')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn($tableName, 'review_reason_reference_id')) {
            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->unsignedBigInteger('review_reason_reference_id')->nullable();
                $table->index('review_reason_reference_id', $prefix.'_review_reason_idx');
                $table->foreign('review_reason_reference_id', $prefix.'_review_reason_fk')
                    ->references('id')->on('reference_values')->cascadeOnUpdate()->nullOnDelete();
            });
        }
    }

    private function dropReasonColumns(string $tableName, string $prefix): void
    {
        if (! Schema::hasTable($tableName)) return;

        if (Schema::hasColumn($tableName, 'review_reason_reference_id')) {
            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->dropForeign($prefix.'_review_reason_fk');
                $table->dropIndex($prefix.'_review_reason_idx');
                $table->dropColumn('review_reason_reference_id');
            });
        }

        if (Schema::hasColumn($tableName, 'reason_reference_id')) {
            Schema::table($tableName, function (Blueprint $table) use ($prefix) {
                $table->dropForeign($prefix.'_reason_fk');
                $table->dropIndex($prefix.'_reason_idx');
                $table->dropColumn('reason_reference_id');
            });
        }
    }
};
