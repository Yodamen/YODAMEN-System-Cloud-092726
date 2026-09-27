<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Posting columns are reconciled by 2026_09_22_000100.
        // This migration owns the refund ledger tables and approval mapping.

        if (! Schema::hasTable('transaction_refunds')) {
            Schema::create('transaction_refunds', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('transaction_action_request_id');
                $table->string('code', 80);
                $table->decimal('adjustment_amount', 14, 2)->default(0);
                $table->decimal('cash_refund_amount', 14, 2)->default(0);
                $table->decimal('balance_reduction_amount', 14, 2)->default(0);
                $table->decimal('correction_refund_due_settlement_amount', 14, 2)->default(0);
                $table->string('refund_method', 80)->nullable();
                $table->string('refund_reference', 180)->nullable();
                $table->string('reason_code', 80);
                $table->text('remarks');
                $table->string('status', 20)->default('POSTED');
                $table->unsignedBigInteger('processed_by');
                $table->timestamp('processed_at');
                $table->timestamps();

                $table->unique('transaction_action_request_id', 'tx_refund_action_req_uq');
                $table->unique('code', 'tx_refund_code_uq');
                $table->index('status', 'tx_refund_status_idx');
                $table->index(['sale_id', 'processed_at'], 'tx_refund_sale_processed_idx');

                $table->foreign('business_id', 'tx_refund_business_fk')
                    ->references('id')->on('businesses')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('branch_id', 'tx_refund_branch_fk')
                    ->references('id')->on('branches')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('sale_id', 'tx_refund_sale_fk')
                    ->references('id')->on('sales')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('transaction_action_request_id', 'tx_refund_action_req_fk')
                    ->references('id')->on('transaction_action_requests')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('processed_by', 'tx_refund_processed_by_fk')
                    ->references('id')->on('users')->cascadeOnUpdate()->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('transaction_refund_lines')) {
            Schema::create('transaction_refund_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transaction_refund_id');
                $table->unsignedBigInteger('transaction_action_request_line_id');
                $table->unsignedBigInteger('sale_item_id');
                $table->decimal('refund_quantity', 14, 4)->default(0);
                $table->decimal('requested_amount', 14, 2)->default(0);
                $table->decimal('economic_adjustment_amount', 14, 2)->default(0);
                $table->decimal('cash_refund_amount', 14, 2)->default(0);
                $table->decimal('balance_reduction_amount', 14, 2)->default(0);
                $table->decimal('correction_refund_due_settlement_amount', 14, 2)->default(0);
                $table->boolean('return_to_stock')->nullable();
                $table->decimal('stock_quantity_returned', 14, 4)->default(0);
                $table->json('item_snapshot')->nullable();
                $table->timestamps();

                $table->unique('transaction_action_request_line_id', 'tx_refund_line_action_line_uq');
                $table->index(['sale_item_id', 'created_at'], 'tx_refund_line_sale_created_idx');

                $table->foreign('transaction_refund_id', 'tx_refund_line_refund_fk')
                    ->references('id')->on('transaction_refunds')->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreign('transaction_action_request_line_id', 'tx_refund_line_action_line_fk')
                    ->references('id')->on('transaction_action_request_lines')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('sale_item_id', 'tx_refund_line_sale_item_fk')
                    ->references('id')->on('sale_items')->cascadeOnUpdate()->restrictOnDelete();
            });
        }

        $this->assertRequiredColumns('transaction_refunds', [
            'id', 'business_id', 'branch_id', 'sale_id', 'transaction_action_request_id',
            'code', 'adjustment_amount', 'cash_refund_amount', 'balance_reduction_amount',
            'correction_refund_due_settlement_amount', 'refund_method', 'refund_reference',
            'reason_code', 'remarks', 'status', 'processed_by', 'processed_at', 'created_at', 'updated_at',
        ]);

        $this->assertRequiredColumns('transaction_refund_lines', [
            'id', 'transaction_refund_id', 'transaction_action_request_line_id', 'sale_item_id',
            'refund_quantity', 'requested_amount', 'economic_adjustment_amount', 'cash_refund_amount',
            'balance_reduction_amount', 'correction_refund_due_settlement_amount', 'return_to_stock',
            'stock_quantity_returned', 'item_snapshot', 'created_at', 'updated_at',
        ]);

        $this->ensureMySqlForeign('transaction_refunds', 'business_id', 'businesses', 'tx_refund_business_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_refunds', 'branch_id', 'branches', 'tx_refund_branch_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_refunds', 'sale_id', 'sales', 'tx_refund_sale_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_refunds', 'transaction_action_request_id', 'transaction_action_requests', 'tx_refund_action_req_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_refunds', 'processed_by', 'users', 'tx_refund_processed_by_fk', 'restrict');

        $this->ensureMySqlForeign('transaction_refund_lines', 'transaction_refund_id', 'transaction_refunds', 'tx_refund_line_refund_fk', 'cascade');
        $this->ensureMySqlForeign('transaction_refund_lines', 'transaction_action_request_line_id', 'transaction_action_request_lines', 'tx_refund_line_action_line_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_refund_lines', 'sale_item_id', 'sale_items', 'tx_refund_line_sale_item_fk', 'restrict');

        $this->ensureMySqlIndex('transaction_refunds', 'tx_refund_action_req_uq', ['transaction_action_request_id'], true);
        $this->ensureMySqlIndex('transaction_refunds', 'tx_refund_code_uq', ['code'], true);
        $this->ensureMySqlIndex('transaction_refunds', 'tx_refund_status_idx', ['status']);
        $this->ensureMySqlIndex('transaction_refunds', 'tx_refund_sale_processed_idx', ['sale_id', 'processed_at']);
        $this->ensureMySqlIndex('transaction_refund_lines', 'tx_refund_line_action_line_uq', ['transaction_action_request_line_id'], true);
        $this->ensureMySqlIndex('transaction_refund_lines', 'tx_refund_line_sale_created_idx', ['sale_item_id', 'created_at']);

        $approvalPermissionId = DB::table('permissions')->where('code', 'sales.APPROVE_ACTION')->value('id');
        if ($approvalPermissionId) {
            $managerRoleIds = DB::table('roles')->whereIn('code', ['ROLE-MGR'])->pluck('id');
            foreach ($managerRoleIds as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $approvalPermissionId],
                    ['updated_at' => now(), 'created_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_refund_lines');
        Schema::dropIfExists('transaction_refunds');
    }

    private function assertRequiredColumns(string $tableName, array $columns): void
    {
        $missing = array_values(array_filter(
            $columns,
            fn (string $column) => ! Schema::hasColumn($tableName, $column),
        ));

        if ($missing !== []) {
            throw new \RuntimeException(
                sprintf(
                    'Existing table %s is incomplete. Missing columns: %s. Stop and repair before continuing.',
                    $tableName,
                    implode(', ', $missing),
                ),
            );
        }
    }

    private function ensureMySqlForeign(
        string $tableName,
        string $columnName,
        string $referencedTable,
        string $constraintName,
        string $onDelete,
    ): void {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasColumn($tableName, $columnName) || ! Schema::hasTable($referencedTable)) {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', $columnName)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        if ($exists) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use (
            $columnName,
            $referencedTable,
            $constraintName,
            $onDelete,
        ) {
            $foreign = $table->foreign($columnName, $constraintName)
                ->references('id')
                ->on($referencedTable)
                ->cascadeOnUpdate();

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } elseif ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }

    private function ensureMySqlIndex(
        string $tableName,
        string $indexName,
        array $columns,
        bool $unique = false,
    ): void {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        $exists = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $tableName)
            ->where('INDEX_NAME', $indexName)
            ->exists();

        if ($exists) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName, $columns, $unique) {
            if ($unique) {
                $table->unique($columns, $indexName);
            } else {
                $table->index($columns, $indexName);
            }
        });
    }
};
