<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transaction_action_requests')) {
            Schema::create('transaction_action_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('business_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('sale_id');
                $table->string('code', 80);
                $table->string('action_type', 30);
                $table->string('status', 40)->default('PENDING_MANAGER_APPROVAL');
                $table->string('reason_code', 80);
                $table->string('reason_label', 160);
                $table->text('remarks');
                $table->json('original_snapshot');
                $table->json('proposed_snapshot')->nullable();
                $table->json('impact_preview')->nullable();
                $table->char('sale_version_hash', 64);
                $table->string('refund_method', 80)->nullable();
                $table->string('refund_reference', 180)->nullable();
                $table->decimal('requested_refund_amount', 14, 2)->default(0);
                $table->unsignedBigInteger('requested_by');
                $table->timestamp('requested_at');
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('manager_remarks')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->unsignedBigInteger('posted_by')->nullable();
                $table->timestamps();

                $table->unique('code', 'tx_action_req_code_uq');
                $table->index('action_type', 'tx_action_req_type_idx');
                $table->index('status', 'tx_action_req_status_idx');
                $table->index('reason_code', 'tx_action_req_reason_idx');
                $table->index(['sale_id', 'status'], 'tx_action_req_sale_status_idx');
                $table->index(['business_id', 'status', 'requested_at'], 'tx_action_req_biz_status_req_idx');

                $table->foreign('business_id', 'tx_action_req_business_fk')
                    ->references('id')->on('businesses')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('branch_id', 'tx_action_req_branch_fk')
                    ->references('id')->on('branches')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('sale_id', 'tx_action_req_sale_fk')
                    ->references('id')->on('sales')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('requested_by', 'tx_action_req_requested_by_fk')
                    ->references('id')->on('users')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreign('reviewed_by', 'tx_action_req_reviewed_by_fk')
                    ->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreign('posted_by', 'tx_action_req_posted_by_fk')
                    ->references('id')->on('users')->cascadeOnUpdate()->nullOnDelete();
            });
        }

        if (! Schema::hasTable('transaction_action_request_lines')) {
            Schema::create('transaction_action_request_lines', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transaction_action_request_id');
                $table->unsignedBigInteger('sale_item_id')->nullable();
                $table->string('line_action', 30)->default('EDIT');
                $table->json('original_snapshot');
                $table->json('proposed_snapshot')->nullable();
                $table->decimal('refund_quantity', 14, 4)->default(0);
                $table->decimal('refund_amount', 14, 2)->default(0);
                $table->boolean('return_to_stock')->nullable();
                $table->timestamps();

                $table->index(
                    ['transaction_action_request_id', 'sale_item_id'],
                    'tx_action_request_line_idx'
                );

                // Explicit short names avoid MySQL's 64-character identifier limit.
                $table->foreign('transaction_action_request_id', 'tx_action_line_request_fk')
                    ->references('id')->on('transaction_action_requests')
                    ->cascadeOnUpdate()->cascadeOnDelete();
                $table->foreign('sale_item_id', 'tx_action_line_sale_item_fk')
                    ->references('id')->on('sale_items')
                    ->cascadeOnUpdate()->restrictOnDelete();
            });
        }

        $this->assertRequiredColumns('transaction_action_requests', [
            'id', 'business_id', 'branch_id', 'sale_id', 'code', 'action_type', 'status',
            'reason_code', 'reason_label', 'remarks', 'original_snapshot', 'proposed_snapshot',
            'impact_preview', 'sale_version_hash', 'refund_method', 'refund_reference',
            'requested_refund_amount', 'requested_by', 'requested_at', 'reviewed_by',
            'reviewed_at', 'manager_remarks', 'posted_at', 'posted_by', 'created_at', 'updated_at',
        ]);

        $this->assertRequiredColumns('transaction_action_request_lines', [
            'id', 'transaction_action_request_id', 'sale_item_id', 'line_action',
            'original_snapshot', 'proposed_snapshot', 'refund_quantity', 'refund_amount',
            'return_to_stock', 'created_at', 'updated_at',
        ]);

        // Recover constraints/indexes if MySQL previously created the table but failed
        // while adding a long auto-generated identifier.
        $this->ensureMySqlForeign('transaction_action_requests', 'business_id', 'businesses', 'tx_action_req_business_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_action_requests', 'branch_id', 'branches', 'tx_action_req_branch_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_action_requests', 'sale_id', 'sales', 'tx_action_req_sale_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_action_requests', 'requested_by', 'users', 'tx_action_req_requested_by_fk', 'restrict');
        $this->ensureMySqlForeign('transaction_action_requests', 'reviewed_by', 'users', 'tx_action_req_reviewed_by_fk', 'set null');
        $this->ensureMySqlForeign('transaction_action_requests', 'posted_by', 'users', 'tx_action_req_posted_by_fk', 'set null');

        $this->ensureMySqlForeign('transaction_action_request_lines', 'transaction_action_request_id', 'transaction_action_requests', 'tx_action_line_request_fk', 'cascade');
        $this->ensureMySqlForeign('transaction_action_request_lines', 'sale_item_id', 'sale_items', 'tx_action_line_sale_item_fk', 'restrict');

        $this->ensureMySqlIndex('transaction_action_requests', 'tx_action_req_code_uq', ['code'], true);
        $this->ensureMySqlIndex('transaction_action_requests', 'tx_action_req_type_idx', ['action_type']);
        $this->ensureMySqlIndex('transaction_action_requests', 'tx_action_req_status_idx', ['status']);
        $this->ensureMySqlIndex('transaction_action_requests', 'tx_action_req_reason_idx', ['reason_code']);
        $this->ensureMySqlIndex('transaction_action_requests', 'tx_action_req_sale_status_idx', ['sale_id', 'status']);
        $this->ensureMySqlIndex('transaction_action_requests', 'tx_action_req_biz_status_req_idx', ['business_id', 'status', 'requested_at']);
        $this->ensureMySqlIndex('transaction_action_request_lines', 'tx_action_request_line_idx', ['transaction_action_request_id', 'sale_item_id']);

        $permissions = [
            ['code' => 'sales.CORRECT', 'module' => 'sales', 'action' => 'CORRECT', 'name' => 'Sales - Request Correction'],
            ['code' => 'sales.REFUND', 'module' => 'sales', 'action' => 'REFUND', 'name' => 'Sales - Request Refund'],
            ['code' => 'sales.REVERSE', 'module' => 'sales', 'action' => 'REVERSE', 'name' => 'Sales - Request Reversal'],
            ['code' => 'sales.APPROVE_ACTION', 'module' => 'sales', 'action' => 'APPROVE_ACTION', 'name' => 'Sales - Approve Correction / Refund / Reversal'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $permission['code']],
                array_merge($permission, ['updated_at' => now(), 'created_at' => now()]),
            );
        }

        $permissionMap = DB::table('permissions')
            ->whereIn('code', array_column($permissions, 'code'))
            ->pluck('id', 'code');

        $salesEditPermissionId = DB::table('permissions')
            ->where('module', 'sales')
            ->where('action', 'EDIT')
            ->value('id');

        if ($salesEditPermissionId) {
            $requestRoleIds = DB::table('role_permissions')
                ->where('permission_id', $salesEditPermissionId)
                ->pluck('role_id');

            foreach ($requestRoleIds as $roleId) {
                foreach (['sales.CORRECT', 'sales.REFUND', 'sales.REVERSE'] as $code) {
                    if (! isset($permissionMap[$code])) {
                        continue;
                    }

                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $roleId, 'permission_id' => $permissionMap[$code]],
                        ['updated_at' => now(), 'created_at' => now()],
                    );
                }
            }
        }

        $managerRoleIds = DB::table('roles')
            ->whereIn('code', ['ROLE-MGR'])
            ->pluck('id');

        foreach ($managerRoleIds as $roleId) {
            if (! isset($permissionMap['sales.APPROVE_ACTION'])) {
                continue;
            }

            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionMap['sales.APPROVE_ACTION']],
                ['updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['sales.CORRECT', 'sales.REFUND', 'sales.REVERSE', 'sales.APPROVE_ACTION'])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('transaction_action_request_lines');
        Schema::dropIfExists('transaction_action_requests');
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
