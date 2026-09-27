<?php

namespace App\Services\Development;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DevelopmentResetService
{
    /**
     * Business/operational tables removed by a full development reset.
     *
     * The order is deliberately child-to-parent so the reset remains safe with
     * foreign-key enforcement enabled. Master/reference/configuration records
     * are intentionally excluded and remain available after the reset.
     *
     * @var list<string>
     */
    public const RESET_TABLES = [
        'expense_lines',
        'expense_reversal_requests',
        'expenses',
        'profit_sharing_lines',
        'profit_sharing_runs',
        'bank_reconciliation_items',
        'bank_reconciliations',
        'cash_deposits',
        'cash_remittances',
        'business_funding_transactions',
        'petty_cash_liquidations',
        'petty_cash_transactions',
        'accounting_daily_closings',
        'opening_customer_receivables',
        'accounting_opening_balances',
        'accounting_journal_lines',
        'accounting_journal_entries',
        'payroll_cash_advance_allocations',
        'payroll_cash_advance_waivers',
        'payroll_payment_splits',
        'payroll_lines',
        'payroll_runs',
        'employee_cash_advance_ledger',
        'employee_cash_advances',
        'payroll_adjustments',
        'daily_compensations',
        'commission_earnings',
        'attendance_proofs',
        'attendances',
        'leave_requests',
        'leave_balances',
        'personnel_schedule_overrides',
        'z_readings',
        'inventory_count_lines',
        'inventory_counts',
        'inventory_stock_return_refunds',
        'inventory_stock_return_lines',
        'inventory_stock_returns',
        'inventory_receipt_action_refunds',
        'inventory_receipt_action_lines',
        'inventory_receipt_action_requests',
        'inventory_receipt_lines',
        'inventory_receipts',
        'inventory_adjustment_requests',
        'service_consumption_lines',
        'service_consumptions',
        'inventory_movements',
        'inventory_stocks',
        'retail_product_approval_requests',
        'service_credit_ledger',
        'service_credit_usages',
        'service_credits',
        'transaction_refund_lines',
        'transaction_refunds',
        'transaction_action_request_lines',
        'transaction_action_requests',
        'staff_tips',
        'payment_applications',
        'package_enrollments',
        'payments',
        'sale_items',
        'sales',
        'service_completion_events',
        'visit_retail_lines',
        'visit_service_lines',
        'visits',
        'queue_retail_lines',
        'queue_service_lines',
        'queue_entries',
        'booking_rebookings',
        'booking_retail_lines',
        'booking_service_lines',
        'bookings',
        'customers',
        'idempotency_requests',
        'audit_logs',
    ];

    /**
     * Test-only reset order. Audit logs, idempotency records, and all master
     * data stay intact. Only rows belonging to customers explicitly marked as
     * test customers are targeted.
     *
     * @var list<string>
     */
    public const TEST_RESET_TABLES = [
        'accounting_journal_lines',
        'accounting_journal_entries',
        'transaction_refund_lines',
        'transaction_refunds',
        'commission_earnings',
        'service_consumption_lines',
        'service_consumptions',
        'inventory_movements',
        'service_credit_ledger',
        'service_credit_usages',
        'service_credits',
        'staff_tips',
        'payment_applications',
        'package_enrollments',
        'payments',
        'transaction_action_request_lines',
        'transaction_action_requests',
        'sale_items',
        'sales',
        'service_completion_events',
        'visit_retail_lines',
        'visit_service_lines',
        'visits',
        'queue_retail_lines',
        'queue_service_lines',
        'queue_entries',
        'booking_rebookings',
        'booking_retail_lines',
        'booking_service_lines',
        'bookings',
        'customers',
    ];

    /** @var list<string> */
    public const PRESERVED_TABLES = [
        'businesses',
        'branches',
        'users',
        'roles',
        'permissions',
        'role_permissions',
        'reference_groups',
        'reference_values',
        'services',
        'service_prices',
        'personnel',
        'personnel_work_schedules',
        'personnel_compensation_terms',
        'leave_types',
        'commission_rules',
        'discount_rules',
        'customer_type_criteria',
        'customer_class_criteria',
        'retail_products',
        'retail_product_prices',
        'suppliers',
        'supplier_items',
        'service_consumption_recipes',
        'service_consumption_recipe_lines',
        'business_branding_settings',
        'chart_of_accounts',
        'accounting_product_mappings',
        'accounting_payment_method_mappings',
        'code_sequences (master/setup only)',
        'migrations',
    ];

    /** @var list<string> */
    private const MASTER_SEQUENCE_KEYS = [
        'service',
        'service-price',
        'personnel',
        'users',
    ];

    /** @var list<string> */
    private const MASTER_SEQUENCE_PREFIXES = [
        'SUPPLIER:',
        'COMMISSION_RULE:',
        'DISCOUNT_RULE:',
    ];

    /** @return array<string, int> */
    public function preview(): array
    {
        $counts = [];

        foreach (self::RESET_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $counts[$table] = DB::table($table)->count();
        }

        if (Schema::hasTable('code_sequences')) {
            $counts['code_sequences (transactional)'] = $this->transactionalSequenceQuery()->count();
        }

        return $counts;
    }

    /**
     * Full reset of development transactional data.
     *
     * Besides deleting operational rows, this deliberately rewinds the
     * auto-increment/identity counters for every cleared table and removes
     * transactional code-sequence rows. As a result, Booking IDs/codes, Sale
     * IDs/codes, Customer IDs/codes, receipts, payroll runs, etc. start again
     * from their initial sequence after a full DEVRESET. Master/setup sequence
     * rows are preserved to avoid colliding with retained setup records.
     *
     * @return array<string, int>
     */
    public function execute(): array
    {
        $deleted = [];

        // DEVRESET intentionally clears the complete transactional graph while
        // preserving master/setup data. Some transaction tables use RESTRICT
        // foreign keys (for example commission_earnings -> sale_items), and new
        // transactional relationships may be added over time. Temporarily
        // suspending FK checks makes the reset independent of delete ordering,
        // while the zero-row verification below prevents a partial reset from
        // being accepted silently. Constraints are always restored in finally.
        Schema::disableForeignKeyConstraints();

        try {
            DB::transaction(function () use (&$deleted): void {
                foreach (self::RESET_TABLES as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    $deleted[$table] = DB::table($table)->delete();
                }

                if (Schema::hasTable('code_sequences')) {
                    $deleted['code_sequences (transactional)'] = $this->transactionalSequenceQuery()->delete();
                }

                $remaining = [];
                foreach (self::RESET_TABLES as $table) {
                    if (! Schema::hasTable($table)) {
                        continue;
                    }

                    $count = DB::table($table)->count();
                    if ($count > 0) {
                        $remaining[$table] = $count;
                    }
                }

                if ($remaining !== []) {
                    throw new RuntimeException(
                        'DEVRESET verification failed. Transactional rows remain in: '.
                        collect($remaining)->map(fn (int $count, string $table) => $table.'='.$count)->implode(', ')
                    );
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        foreach (self::RESET_TABLES as $table) {
            if (Schema::hasTable($table)) {
                $this->resetIdentity($table);
            }
        }

        return $deleted;
    }

    private function transactionalSequenceQuery(): Builder
    {
        $query = DB::table('code_sequences')
            ->whereNotIn('sequence_key', self::MASTER_SEQUENCE_KEYS);

        foreach (self::MASTER_SEQUENCE_PREFIXES as $prefix) {
            $query->where('sequence_key', 'not like', $prefix.'%');
        }

        return $query;
    }

    private function resetIdentity(string $table): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `'.str_replace('`', '``', $table).'` AUTO_INCREMENT = 1');
            return;
        }

        if ($driver === 'sqlite') {
            try {
                DB::table('sqlite_sequence')->where('name', $table)->delete();
            } catch (\Throwable) {
                // sqlite_sequence is absent when no AUTOINCREMENT table has
                // created it yet. There is nothing to rewind in that case.
            }
            return;
        }

        if ($driver === 'pgsql') {
            $sequence = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS sequence_name", [$table]);
            $name = $sequence?->sequence_name ?? null;
            if ($name) {
                DB::statement('ALTER SEQUENCE '.$this->quotePgIdentifier((string) $name).' RESTART WITH 1');
            }
        }
    }

    private function quotePgIdentifier(string $identifier): string
    {
        return collect(explode('.', $identifier))
            ->map(fn (string $part) => '"'.str_replace('"', '""', $part).'"')
            ->implode('.');
    }

    /**
     * @return list<array{id:int, code:string, name:string}>
     */
    public function testCustomers(): array
    {
        $this->assertTestCustomerColumnExists();

        return DB::table('customers')
            ->where('is_test_customer', true)
            ->orderBy('id')
            ->get(['id', 'code', 'first_name', 'last_name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => trim((string) $row->first_name.' '.(string) $row->last_name),
            ])
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    public function previewTest(): array
    {
        $context = $this->buildTestContext();
        $counts = [];

        foreach ($this->testScopedQueries($context) as $table => $query) {
            $counts[$table] = $query->count();
        }

        return $counts;
    }

    /**
     * Delete only customers explicitly marked as test data and every current
     * operational/financial row linked to those customers.
     *
     * @return array<string, int>
     */
    public function executeTest(): array
    {
        $context = $this->buildTestContext();
        $deleted = array_fill_keys(self::TEST_RESET_TABLES, 0);

        if ($context['customer_ids'] === []) {
            return $deleted;
        }

        DB::transaction(function () use ($context, &$deleted): void {
            $this->restoreInventoryStockForRemovedTestMovements($context['inventory_movement_ids']);

            foreach ($this->testScopedQueries($context) as $table => $query) {
                $deleted[$table] = $query->delete();
            }
        });

        return $deleted;
    }

    /**
     * @return array{
     *   customer_ids:list<int>, booking_ids:list<int>, queue_ids:list<int>,
     *   visit_ids:list<int>, sale_ids:list<int>, sale_item_ids:list<int>,
     *   transaction_action_request_ids:list<int>, transaction_refund_ids:list<int>, payment_ids:list<int>,
     *   payment_application_ids:list<int>, package_ids:list<int>, service_consumption_ids:list<int>,
     *   service_consumption_line_ids:list<int>, inventory_movement_ids:list<int>, commission_earning_ids:list<int>,
     *   service_credit_ledger_ids:list<int>, staff_tip_ids:list<int>, accounting_journal_entry_ids:list<int>
     * }
     */
    private function buildTestContext(): array
    {
        $this->assertTestCustomerColumnExists();

        $customerIds = $this->ids(DB::table('customers')->where('is_test_customer', true)->pluck('id'));

        if ($customerIds === []) {
            return [
                'customer_ids' => [], 'booking_ids' => [], 'queue_ids' => [], 'visit_ids' => [],
                'sale_ids' => [], 'sale_item_ids' => [], 'transaction_action_request_ids' => [],
                'transaction_refund_ids' => [], 'payment_ids' => [], 'payment_application_ids' => [],
                'package_ids' => [], 'service_consumption_ids' => [], 'service_consumption_line_ids' => [],
                'inventory_movement_ids' => [], 'commission_earning_ids' => [], 'service_credit_ledger_ids' => [],
                'staff_tip_ids' => [], 'accounting_journal_entry_ids' => [],
            ];
        }

        $bookingIds = $this->ids(DB::table('bookings')->whereIn('customer_id', $customerIds)->pluck('id'));
        $queueIds = $this->ids(DB::table('queue_entries')->whereIn('customer_id', $customerIds)->pluck('id'));
        $visitIds = $this->ids(DB::table('visits')->whereIn('customer_id', $customerIds)->pluck('id'));
        $saleIds = $this->ids(DB::table('sales')->whereIn('customer_id', $customerIds)->pluck('id'));
        $saleItemIds = $saleIds === [] ? [] : $this->ids(DB::table('sale_items')->whereIn('sale_id', $saleIds)->pluck('id'));
        $transactionActionRequestIds = $saleIds === [] || ! Schema::hasTable('transaction_action_requests')
            ? [] : $this->ids(DB::table('transaction_action_requests')->whereIn('sale_id', $saleIds)->pluck('id'));
        $transactionRefundIds = $saleIds === [] || ! Schema::hasTable('transaction_refunds')
            ? [] : $this->ids(DB::table('transaction_refunds')->whereIn('sale_id', $saleIds)->pluck('id'));
        $paymentIds = $this->ids(DB::table('payments')->whereIn('customer_id', $customerIds)->pluck('id'));
        $packageIds = $this->ids(DB::table('package_enrollments')->whereIn('customer_id', $customerIds)->pluck('id'));
        $serviceConsumptionIds = $visitIds === [] || ! Schema::hasTable('service_consumptions')
            ? [] : $this->ids(DB::table('service_consumptions')->whereIn('visit_id', $visitIds)->pluck('id'));
        $serviceConsumptionLineIds = $serviceConsumptionIds === [] || ! Schema::hasTable('service_consumption_lines')
            ? [] : $this->ids(DB::table('service_consumption_lines')->whereIn('service_consumption_id', $serviceConsumptionIds)->pluck('id'));

        $paymentApplicationIds = Schema::hasTable('payment_applications')
            ? $this->ids($this->paymentApplicationQuery([
                'payment_ids' => $paymentIds, 'sale_ids' => $saleIds, 'sale_item_ids' => $saleItemIds, 'package_ids' => $packageIds,
            ])->pluck('id'))
            : [];

        $commissionEarningIds = Schema::hasTable('commission_earnings')
            ? $this->ids(DB::table('commission_earnings')->where(function (Builder $query) use ($customerIds, $saleIds, $saleItemIds): void {
                $query->whereIn('customer_id', $customerIds);
                if ($saleIds !== []) $query->orWhereIn('sale_id', $saleIds);
                if ($saleItemIds !== []) $query->orWhereIn('sale_item_id', $saleItemIds);
            })->pluck('id'))
            : [];

        $serviceCreditLedgerIds = Schema::hasTable('service_credit_ledger')
            ? $this->ids(DB::table('service_credit_ledger')->whereIn('customer_id', $customerIds)->pluck('id'))
            : [];

        $staffTipIds = Schema::hasTable('staff_tips')
            ? $this->ids(DB::table('staff_tips')->whereIn('customer_id', $customerIds)->pluck('id'))
            : [];

        $inventoryMovementIds = [];
        if (Schema::hasTable('inventory_movements')) {
            $inventoryMovementIds = $this->ids(DB::table('inventory_movements')->where(function (Builder $query) use ($saleIds, $saleItemIds, $transactionActionRequestIds, $serviceConsumptionLineIds): void {
                $hasCondition = false;
                foreach ([
                    ['sale_id', $saleIds],
                    ['sale_item_id', $saleItemIds],
                    ['transaction_action_request_id', $transactionActionRequestIds],
                    ['source_service_consumption_line_id', $serviceConsumptionLineIds],
                ] as [$column, $ids]) {
                    if ($ids === []) continue;
                    $hasCondition ? $query->orWhereIn($column, $ids) : $query->whereIn($column, $ids);
                    $hasCondition = true;
                }
                if (! $hasCondition) $query->whereRaw('1 = 0');
            })->pluck('id'));
        }

        $journalEntryIds = $this->journalEntryIdsForTestContext([
            'sale_ids' => $saleIds,
            'payment_ids' => $paymentIds,
            'payment_application_ids' => $paymentApplicationIds,
            'inventory_movement_ids' => $inventoryMovementIds,
            'commission_earning_ids' => $commissionEarningIds,
            'service_credit_ledger_ids' => $serviceCreditLedgerIds,
            'staff_tip_ids' => $staffTipIds,
            'transaction_action_request_ids' => $transactionActionRequestIds,
            'transaction_refund_ids' => $transactionRefundIds,
        ]);

        return [
            'customer_ids' => $customerIds,
            'booking_ids' => $bookingIds,
            'queue_ids' => $queueIds,
            'visit_ids' => $visitIds,
            'sale_ids' => $saleIds,
            'sale_item_ids' => $saleItemIds,
            'transaction_action_request_ids' => $transactionActionRequestIds,
            'transaction_refund_ids' => $transactionRefundIds,
            'payment_ids' => $paymentIds,
            'payment_application_ids' => $paymentApplicationIds,
            'package_ids' => $packageIds,
            'service_consumption_ids' => $serviceConsumptionIds,
            'service_consumption_line_ids' => $serviceConsumptionLineIds,
            'inventory_movement_ids' => $inventoryMovementIds,
            'commission_earning_ids' => $commissionEarningIds,
            'service_credit_ledger_ids' => $serviceCreditLedgerIds,
            'staff_tip_ids' => $staffTipIds,
            'accounting_journal_entry_ids' => $journalEntryIds,
        ];
    }

    /** @return list<int> */
    private function journalEntryIdsForTestContext(array $context): array
    {
        if (! Schema::hasTable('accounting_journal_entries')) return [];

        $sources = [
            ['SALE', $context['sale_ids']],
            ['PAYMENT', $context['payment_ids']],
            ['PAYMENT_APPLICATION', $context['payment_application_ids']],
            ['INVENTORY_MOVEMENT', $context['inventory_movement_ids']],
            ['COMMISSION_EARNING', $context['commission_earning_ids']],
            ['SERVICE_CREDIT_LEDGER', $context['service_credit_ledger_ids']],
            ['STAFF_TIP', $context['staff_tip_ids']],
            ['TRANSACTION_ACTION', $context['transaction_action_request_ids']],
            ['TRANSACTION_REFUND', $context['transaction_refund_ids']],
        ];

        return $this->ids(DB::table('accounting_journal_entries')->where(function (Builder $query) use ($sources): void {
            $used = false;
            foreach ($sources as [$sourceType, $ids]) {
                if ($ids === []) continue;
                $callback = fn (Builder $part) => $part->where('source_type', $sourceType)->whereIn('source_id', $ids);
                $used ? $query->orWhere($callback) : $query->where($callback);
                $used = true;
            }
            if (! $used) $query->whereRaw('1 = 0');
        })->pluck('id'));
    }

    /**
     * @param array<string, list<int>> $context
     * @return array<string, Builder>
     */
    private function testScopedQueries(array $context): array
    {
        return [
            'accounting_journal_lines' => $this->whereIds('accounting_journal_lines', 'journal_entry_id', $context['accounting_journal_entry_ids']),
            'accounting_journal_entries' => $this->whereIds('accounting_journal_entries', 'id', $context['accounting_journal_entry_ids']),
            'transaction_refund_lines' => $this->whereIds('transaction_refund_lines', 'transaction_refund_id', $context['transaction_refund_ids']),
            'transaction_refunds' => $this->whereIds('transaction_refunds', 'id', $context['transaction_refund_ids']),
            'commission_earnings' => $this->whereIds('commission_earnings', 'id', $context['commission_earning_ids']),
            'service_consumption_lines' => $this->whereIds('service_consumption_lines', 'id', $context['service_consumption_line_ids']),
            'service_consumptions' => $this->whereIds('service_consumptions', 'id', $context['service_consumption_ids']),
            'inventory_movements' => $this->whereIds('inventory_movements', 'id', $context['inventory_movement_ids']),
            'service_credit_ledger' => $this->whereIds('service_credit_ledger', 'id', $context['service_credit_ledger_ids']),
            'service_credit_usages' => $this->whereIds('service_credit_usages', 'customer_id', $context['customer_ids']),
            'service_credits' => $this->whereIds('service_credits', 'customer_id', $context['customer_ids']),
            'staff_tips' => $this->whereIds('staff_tips', 'id', $context['staff_tip_ids']),
            'payment_applications' => $this->whereIds('payment_applications', 'id', $context['payment_application_ids']),
            'package_enrollments' => $this->whereIds('package_enrollments', 'customer_id', $context['customer_ids']),
            'payments' => $this->whereIds('payments', 'customer_id', $context['customer_ids']),
            'transaction_action_request_lines' => $this->whereIds('transaction_action_request_lines', 'transaction_action_request_id', $context['transaction_action_request_ids']),
            'transaction_action_requests' => $this->whereIds('transaction_action_requests', 'id', $context['transaction_action_request_ids']),
            'sale_items' => $this->whereIds('sale_items', 'id', $context['sale_item_ids']),
            'sales' => $this->whereIds('sales', 'id', $context['sale_ids']),
            'service_completion_events' => $this->whereIds('service_completion_events', 'customer_id', $context['customer_ids']),
            'visit_retail_lines' => $this->whereIds('visit_retail_lines', 'visit_id', $context['visit_ids']),
            'visit_service_lines' => $this->whereIds('visit_service_lines', 'visit_id', $context['visit_ids']),
            'visits' => $this->whereIds('visits', 'id', $context['visit_ids']),
            'queue_retail_lines' => $this->whereIds('queue_retail_lines', 'queue_entry_id', $context['queue_ids']),
            'queue_service_lines' => $this->whereIds('queue_service_lines', 'queue_entry_id', $context['queue_ids']),
            'queue_entries' => $this->whereIds('queue_entries', 'id', $context['queue_ids']),
            'booking_rebookings' => $this->whereIds('booking_rebookings', 'booking_id', $context['booking_ids']),
            'booking_retail_lines' => $this->whereIds('booking_retail_lines', 'booking_id', $context['booking_ids']),
            'booking_service_lines' => $this->whereIds('booking_service_lines', 'booking_id', $context['booking_ids']),
            'bookings' => $this->whereIds('bookings', 'id', $context['booking_ids']),
            'customers' => $this->whereIds('customers', 'id', $context['customer_ids']),
        ];
    }

    /** @param list<int> $movementIds */
    private function restoreInventoryStockForRemovedTestMovements(array $movementIds): void
    {
        if ($movementIds === [] || ! Schema::hasTable('inventory_movements') || ! Schema::hasTable('inventory_stocks')) {
            return;
        }

        $groups = DB::table('inventory_movements')
            ->whereIn('id', $movementIds)
            ->selectRaw('business_id, branch_id, retail_product_id, SUM(quantity_change) as net_change')
            ->groupBy('business_id', 'branch_id', 'retail_product_id')
            ->get();

        foreach ($groups as $group) {
            $stock = DB::table('inventory_stocks')
                ->where('business_id', $group->business_id)
                ->where('branch_id', $group->branch_id)
                ->where('retail_product_id', $group->retail_product_id)
                ->lockForUpdate()
                ->first();

            if (! $stock) continue;

            DB::table('inventory_stocks')->where('id', $stock->id)->update([
                'on_hand' => round((float) $stock->on_hand - (float) $group->net_change, 4),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param array{
     *   sale_ids:list<int>, sale_item_ids:list<int>, payment_ids:list<int>, package_ids:list<int>
     * } $context
     */
    private function paymentApplicationQuery(array $context): Builder
    {
        $conditions = array_values(array_filter([
            ['payment_id', $context['payment_ids']],
            ['sale_id', $context['sale_ids']],
            ['source_sale_id', $context['sale_ids']],
            ['target_sale_item_id', $context['sale_item_ids']],
            ['package_enrollment_id', $context['package_ids']],
        ], fn (array $condition) => $condition[1] !== []));

        if ($conditions === []) {
            return $this->falseQuery('payment_applications');
        }

        return DB::table('payment_applications')->where(function (Builder $query) use ($conditions): void {
            foreach ($conditions as $index => [$column, $ids]) {
                if ($index === 0) {
                    $query->whereIn($column, $ids);
                } else {
                    $query->orWhereIn($column, $ids);
                }
            }
        });
    }

    /** @param list<int> $ids */
    private function whereIds(string $table, string $column, array $ids): Builder
    {
        if (! Schema::hasTable($table) || $ids === []) {
            return $this->falseQuery($table);
        }

        return DB::table($table)->whereIn($column, $ids);
    }

    private function falseQuery(string $table): Builder
    {
        return DB::table($table)->whereRaw('1 = 0');
    }

    /** @return list<int> */
    private function ids($values): array
    {
        return collect($values)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->values()
            ->all();
    }

    private function assertTestCustomerColumnExists(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'is_test_customer')) {
            throw new RuntimeException('Test Customer support is not installed. Run php artisan migrate before using yodamen:devreset-test.');
        }
    }
}
