<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commission_rules')) {
            return;
        }

        $this->addMissingColumns();
        $this->copyLegacyEmployeeReference();
        $this->ensureServiceForeignKeySupportIndex();
        $this->dropLegacyServiceDateUnique();
        $this->normalizeLegacyNullability();
        $this->normalizeExistingRows();
        $this->ensureIndexes();
    }

    /**
     * Reconciliation is forward-only because removing canonical columns can destroy data.
     */
    public function down(): void
    {
    }

    private function addMissingColumns(): void
    {
        $definitions = [
            'rule_name' => fn (Blueprint $table) => $table->string('rule_name', 160)->nullable(),
            'branch_id' => fn (Blueprint $table) => $table->unsignedBigInteger('branch_id')->nullable(),
            'applies_to' => fn (Blueprint $table) => $table->string('applies_to', 20)->default('ALL'),
            'personnel_id' => fn (Blueprint $table) => $table->unsignedBigInteger('personnel_id')->nullable(),
            'position_reference_id' => fn (Blueprint $table) => $table->unsignedBigInteger('position_reference_id')->nullable(),
            'service_category_reference_id' => fn (Blueprint $table) => $table->unsignedBigInteger('service_category_reference_id')->nullable(),
            'rate_value' => fn (Blueprint $table) => $table->decimal('rate_value', 12, 4)->nullable(),
            'priority' => fn (Blueprint $table) => $table->integer('priority')->default(0),
        ];

        foreach ($definitions as $column => $definition) {
            if (Schema::hasColumn('commission_rules', $column)) {
                continue;
            }

            Schema::table('commission_rules', function (Blueprint $table) use ($definition) {
                $definition($table);
            });
        }
    }

    private function copyLegacyEmployeeReference(): void
    {
        if (! Schema::hasColumn('commission_rules', 'employee_id') || ! Schema::hasColumn('commission_rules', 'personnel_id')) {
            return;
        }

        DB::table('commission_rules')
            ->whereNotNull('employee_id')
            ->whereNull('personnel_id')
            ->update(['personnel_id' => DB::raw('employee_id')]);
    }

    private function normalizeLegacyNullability(): void
    {
        if (Schema::hasColumn('commission_rules', 'service_id')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('service_id')->nullable()->change();
            });
        }

        if (Schema::hasColumn('commission_rules', 'rate_percentage')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->decimal('rate_percentage', 7, 4)->nullable()->change();
            });
        }

        if (Schema::hasColumn('commission_rules', 'basis')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->string('basis', 50)->default('STANDARD_SERVICE_PRICE')->change();
            });
        }
    }

    private function normalizeExistingRows(): void
    {
        $updates = [];

        if (Schema::hasColumn('commission_rules', 'rule_name')) {
            $updates['rule_name'] = DB::raw('COALESCE(rule_name, code)');
        }
        if (Schema::hasColumn('commission_rules', 'applies_to')) {
            $updates['applies_to'] = DB::raw("COALESCE(applies_to, 'ALL')");
        }
        if (Schema::hasColumn('commission_rules', 'rate_value') && Schema::hasColumn('commission_rules', 'rate_percentage')) {
            $updates['rate_value'] = DB::raw('COALESCE(rate_value, rate_percentage)');
        }
        if (Schema::hasColumn('commission_rules', 'basis')) {
            $updates['basis'] = 'STANDARD_SERVICE_PRICE';
        }
        if (Schema::hasColumn('commission_rules', 'priority')) {
            $updates['priority'] = DB::raw('COALESCE(priority, 0)');
        }

        if ($updates !== []) {
            DB::table('commission_rules')->update($updates);
        }
    }

    private function ensureServiceForeignKeySupportIndex(): void
    {
        if (! Schema::hasColumn('commission_rules', 'service_id')) {
            return;
        }

        $hasSupportingIndex = collect(Schema::getIndexes('commission_rules'))
            ->contains(function (array $index) {
                $columns = array_values($index['columns'] ?? []);

                return ($columns[0] ?? null) === 'service_id'
                    && ! ((bool) ($index['unique'] ?? false) && $columns === ['service_id', 'effective_from']);
            });

        if (! $hasSupportingIndex) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->index('service_id', 'commission_rules_service_id_fk_idx');
            });
        }
    }

    private function dropLegacyServiceDateUnique(): void
    {
        foreach (Schema::getIndexes('commission_rules') as $index) {
            $columns = array_values($index['columns'] ?? []);
            $isUnique = (bool) ($index['unique'] ?? false);

            if ($isUnique && $columns === ['service_id', 'effective_from']) {
                Schema::table('commission_rules', function (Blueprint $table) use ($index) {
                    $table->dropUnique($index['name']);
                });
                break;
            }
        }
    }

    private function ensureIndexes(): void
    {
        $indexes = [
            'commission_rules_business_applies_status_idx' => ['business_id', 'applies_to', 'status'],
            'commission_rules_business_personnel_status_idx' => ['business_id', 'personnel_id', 'status'],
            'commission_rules_business_position_status_idx' => ['business_id', 'position_reference_id', 'status'],
            'commission_rules_business_category_status_idx' => ['business_id', 'service_category_reference_id', 'status'],
            'commission_rules_business_branch_status_idx' => ['business_id', 'branch_id', 'status'],
        ];

        $existing = collect(Schema::getIndexes('commission_rules'))
            ->pluck('name')
            ->filter()
            ->all();

        foreach ($indexes as $name => $columns) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            if (collect($columns)->contains(fn (string $column) => ! Schema::hasColumn('commission_rules', $column))) {
                continue;
            }

            Schema::table('commission_rules', function (Blueprint $table) use ($columns, $name) {
                $table->index($columns, $name);
            });
        }
    }
};
