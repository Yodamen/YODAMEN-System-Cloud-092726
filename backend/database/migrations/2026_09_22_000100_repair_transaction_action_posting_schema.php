<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $salesEffectiveAdded = false;
        $saleItemEffectiveAdded = false;
        $serviceConsumptionEffectiveAdded = false;

        if (Schema::hasTable('transaction_action_requests')) {
            $this->addColumn('transaction_action_requests', 'posting_summary', function (Blueprint $table) {
                $table->json('posting_summary')->nullable()->after('manager_remarks');
            });
        }

        if (Schema::hasTable('sales')) {
            $this->addColumn('sales', 'correction_adjustment_amount', fn (Blueprint $table) => $table->decimal('correction_adjustment_amount', 14, 2)->default(0)->after('outstanding_balance'));
            $this->addColumn('sales', 'refund_adjustment_amount', fn (Blueprint $table) => $table->decimal('refund_adjustment_amount', 14, 2)->default(0)->after('correction_adjustment_amount'));
            $this->addColumn('sales', 'cash_refunded_amount', fn (Blueprint $table) => $table->decimal('cash_refunded_amount', 14, 2)->default(0)->after('refund_adjustment_amount'));
            $this->addColumn('sales', 'refund_due_amount', fn (Blueprint $table) => $table->decimal('refund_due_amount', 14, 2)->default(0)->after('cash_refunded_amount'));
            $salesEffectiveAdded = $this->addColumn('sales', 'effective_net_amount', fn (Blueprint $table) => $table->decimal('effective_net_amount', 14, 2)->default(0)->after('refund_due_amount'));
            $this->addColumn('sales', 'refund_status', fn (Blueprint $table) => $table->string('refund_status', 20)->default('NONE')->after('effective_net_amount'));
            $this->addColumn('sales', 'reversed_at', fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable()->after('financial_finalized_at'));
            $this->addColumn('sales', 'reversed_by', fn (Blueprint $table) => $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at'));
            $this->addColumn('sales', 'reversal_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('reversal_action_request_id')->nullable()->after('reversed_by'));

            $this->addIndexIfMissing('sales', 'sales_refund_status_index', fn (Blueprint $table) => $table->index('refund_status'));
            $this->addForeignIfMissing('sales', 'reversed_by', 'users');
            $this->addForeignIfMissing('sales', 'reversal_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('sale_items')) {
            $this->addColumn('sale_items', 'correction_adjustment_amount', fn (Blueprint $table) => $table->decimal('correction_adjustment_amount', 14, 2)->default(0)->after('balance_amount'));
            $this->addColumn('sale_items', 'refund_adjustment_amount', fn (Blueprint $table) => $table->decimal('refund_adjustment_amount', 14, 2)->default(0)->after('correction_adjustment_amount'));
            $this->addColumn('sale_items', 'cash_refunded_amount', fn (Blueprint $table) => $table->decimal('cash_refunded_amount', 14, 2)->default(0)->after('refund_adjustment_amount'));
            $this->addColumn('sale_items', 'refund_due_amount', fn (Blueprint $table) => $table->decimal('refund_due_amount', 14, 2)->default(0)->after('cash_refunded_amount'));
            $this->addColumn('sale_items', 'refunded_quantity', fn (Blueprint $table) => $table->decimal('refunded_quantity', 14, 4)->default(0)->after('refund_due_amount'));
            $this->addColumn('sale_items', 'returned_quantity', fn (Blueprint $table) => $table->decimal('returned_quantity', 14, 4)->default(0)->after('refunded_quantity'));
            $saleItemEffectiveAdded = $this->addColumn('sale_items', 'effective_net_line_total', fn (Blueprint $table) => $table->decimal('effective_net_line_total', 14, 2)->default(0)->after('returned_quantity'));
            $this->addColumn('sale_items', 'correction_status', fn (Blueprint $table) => $table->string('correction_status', 20)->default('ACTIVE')->after('effective_net_line_total'));
            $this->addColumn('sale_items', 'refund_status', fn (Blueprint $table) => $table->string('refund_status', 20)->default('NONE')->after('correction_status'));
            $this->addColumn('sale_items', 'last_transaction_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('last_transaction_action_request_id')->nullable()->after('refund_status'));

            $this->addIndexIfMissing('sale_items', 'sale_items_correction_status_index', fn (Blueprint $table) => $table->index('correction_status'));
            $this->addIndexIfMissing('sale_items', 'sale_items_refund_status_index', fn (Blueprint $table) => $table->index('refund_status'));
            $this->addForeignIfMissing('sale_items', 'last_transaction_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('payments')) {
            $this->addColumn('payments', 'reversed_at', fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable()->after('status'));
            $this->addColumn('payments', 'reversed_by', fn (Blueprint $table) => $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at'));
            $this->addColumn('payments', 'reversal_reason', fn (Blueprint $table) => $table->text('reversal_reason')->nullable()->after('reversed_by'));
            $this->addColumn('payments', 'reversal_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('reversal_action_request_id')->nullable()->after('reversal_reason'));

            $this->addForeignIfMissing('payments', 'reversed_by', 'users');
            $this->addForeignIfMissing('payments', 'reversal_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('payment_applications')) {
            $this->addColumn('payment_applications', 'transaction_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('transaction_action_request_id')->nullable()->after('reversed_at'));
            $this->addForeignIfMissing('payment_applications', 'transaction_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('inventory_movements')) {
            $this->addColumn('inventory_movements', 'transaction_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('transaction_action_request_id')->nullable()->after('sale_item_id'));
            $this->addColumn('inventory_movements', 'transaction_action_request_line_id', fn (Blueprint $table) => $table->unsignedBigInteger('transaction_action_request_line_id')->nullable()->after('transaction_action_request_id'));
            $this->addColumn('inventory_movements', 'source_service_consumption_line_id', fn (Blueprint $table) => $table->unsignedBigInteger('source_service_consumption_line_id')->nullable()->after('transaction_action_request_line_id'));
            $this->addColumn('inventory_movements', 'action_effect_key', fn (Blueprint $table) => $table->string('action_effect_key', 190)->nullable()->after('source_service_consumption_line_id'));

            $this->addForeignIfMissing('inventory_movements', 'transaction_action_request_id', 'transaction_action_requests');
            $this->addForeignIfMissing('inventory_movements', 'transaction_action_request_line_id', 'transaction_action_request_lines');
            $this->addForeignIfMissing('inventory_movements', 'source_service_consumption_line_id', 'service_consumption_lines');
            $this->addUniqueIfMissing('inventory_movements', 'inventory_movements_action_effect_key_unique', 'action_effect_key');
        }

        if (Schema::hasTable('service_consumptions')) {
            $serviceConsumptionEffectiveAdded = $this->addColumn('service_consumptions', 'effective_service_quantity', fn (Blueprint $table) => $table->decimal('effective_service_quantity', 14, 4)->default(0)->after('service_quantity'));
            $this->addColumn('service_consumptions', 'correction_status', fn (Blueprint $table) => $table->string('correction_status', 20)->default('ACTIVE')->after('status'));
            $this->addColumn('service_consumptions', 'last_transaction_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('last_transaction_action_request_id')->nullable()->after('correction_status'));

            $this->addIndexIfMissing('service_consumptions', 'service_consumptions_correction_status_index', fn (Blueprint $table) => $table->index('correction_status'));
            $this->addForeignIfMissing('service_consumptions', 'last_transaction_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('service_credit_usages')) {
            $this->addColumn('service_credit_usages', 'reversed_at', fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable()->after('usage_date'));
            $this->addColumn('service_credit_usages', 'reversed_by', fn (Blueprint $table) => $table->unsignedBigInteger('reversed_by')->nullable()->after('reversed_at'));
            $this->addColumn('service_credit_usages', 'reversal_reason', fn (Blueprint $table) => $table->text('reversal_reason')->nullable()->after('reversed_by'));
            $this->addColumn('service_credit_usages', 'reversal_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('reversal_action_request_id')->nullable()->after('reversal_reason'));

            $this->addForeignIfMissing('service_credit_usages', 'reversed_by', 'users');
            $this->addForeignIfMissing('service_credit_usages', 'reversal_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('service_completion_events')) {
            $this->addColumn('service_completion_events', 'last_transaction_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('last_transaction_action_request_id')->nullable()->after('status'));
            $this->addForeignIfMissing('service_completion_events', 'last_transaction_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('visit_retail_lines')) {
            $this->addColumn('visit_retail_lines', 'correction_status', fn (Blueprint $table) => $table->string('correction_status', 20)->default('ACTIVE')->after('sort_order'));
            $this->addColumn('visit_retail_lines', 'last_transaction_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('last_transaction_action_request_id')->nullable()->after('correction_status'));

            $this->addIndexIfMissing('visit_retail_lines', 'visit_retail_lines_correction_status_index', fn (Blueprint $table) => $table->index('correction_status'));
            $this->addForeignIfMissing('visit_retail_lines', 'last_transaction_action_request_id', 'transaction_action_requests');
        }

        if (Schema::hasTable('commission_earnings')) {
            $this->addColumn('commission_earnings', 'source_transaction_action_request_id', fn (Blueprint $table) => $table->unsignedBigInteger('source_transaction_action_request_id')->nullable()->after('payroll_line_id'));
            $this->addColumn('commission_earnings', 'source_commission_earning_id', fn (Blueprint $table) => $table->unsignedBigInteger('source_commission_earning_id')->nullable()->after('source_transaction_action_request_id'));
            $this->addColumn('commission_earnings', 'transaction_action_effect_key', fn (Blueprint $table) => $table->string('transaction_action_effect_key', 190)->nullable()->after('source_commission_earning_id'));

            $this->addForeignIfMissing('commission_earnings', 'source_transaction_action_request_id', 'transaction_action_requests');
            $this->addForeignIfMissing('commission_earnings', 'source_commission_earning_id', 'commission_earnings');
            $this->addUniqueIfMissing('commission_earnings', 'commission_earnings_transaction_action_effect_key_unique', 'transaction_action_effect_key');
        }

        if ($salesEffectiveAdded) {
            DB::table('sales')->update([
                'effective_net_amount' => DB::raw('COALESCE(net_amount, gross_amount, 0)'),
            ]);
        }

        if ($saleItemEffectiveAdded) {
            DB::table('sale_items')->update([
                'effective_net_line_total' => DB::raw('COALESCE(net_line_total, line_total, 0)'),
            ]);
        }

        if ($serviceConsumptionEffectiveAdded) {
            DB::table('service_consumptions')->update([
                'effective_service_quantity' => DB::raw('service_quantity'),
            ]);
        }
    }

    public function down(): void
    {
        // Repair migration intentionally keeps the Update 99 schema in place.
        // The original Update 99 migration owns rollback of these fields.
    }

    private function addColumn(string $tableName, string $columnName, callable $definition): bool
    {
        if (Schema::hasColumn($tableName, $columnName)) {
            return false;
        }

        Schema::table($tableName, function (Blueprint $table) use ($definition) {
            $definition($table);
        });

        return true;
    }

    private function addForeignIfMissing(string $tableName, string $columnName, string $referencedTable): void
    {
        if (! Schema::hasColumn($tableName, $columnName) || ! Schema::hasTable($referencedTable)) {
            return;
        }

        // The application test suite uses SQLite in-memory. The original Update 99
        // migration already creates these foreign keys on a fresh database, so the
        // repair migration only needs foreign-key discovery/repair on MySQL.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $database = DB::connection()->getDatabaseName();
        $exists = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $tableName)
            ->where('COLUMN_NAME', $columnName)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        if (! $exists) {
            $constraintName = $this->shortForeignName($tableName, $columnName);

            Schema::table($tableName, function (Blueprint $table) use ($columnName, $referencedTable, $constraintName) {
                $table->foreign($columnName, $constraintName)
                    ->references('id')
                    ->on($referencedTable)
                    ->cascadeOnUpdate()
                    ->nullOnDelete();
            });
        }
    }

    private function shortForeignName(string $tableName, string $columnName): string
    {
        // MySQL identifiers are limited to 64 characters. Use a deterministic
        // compact name so long Update 99 relation names cannot fail migration.
        return 'tx99_fk_'.substr(sha1($tableName.'.'.$columnName), 0, 20);
    }

    private function addUniqueIfMissing(string $tableName, string $indexName, string $columnName): void
    {
        // Fresh SQLite test databases already receive this index from Update 99.
        // Avoid MySQL information_schema inspection during SQLite test bootstrap.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columnName, $indexName) {
            $table->unique($columnName, $indexName);
        });
    }

    private function addIndexIfMissing(string $tableName, string $indexName, callable $definition): void
    {
        // Fresh SQLite test databases already receive these indexes from Update 99.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! $this->indexExists($tableName, $indexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($definition) {
                $definition($table);
            });
        }
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        $database = DB::connection()->getDatabaseName();

        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $database)
            ->where('TABLE_NAME', $tableName)
            ->where('INDEX_NAME', $indexName)
            ->exists();
    }
};
