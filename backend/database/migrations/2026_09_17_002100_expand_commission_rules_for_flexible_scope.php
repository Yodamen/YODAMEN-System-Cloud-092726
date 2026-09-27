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

        $this->ensureServiceForeignKeySupportIndex();
        $this->dropLegacyServiceDateUnique();

        $this->addIfMissing('rule_name', fn (Blueprint $table) => $table->string('rule_name', 160)->nullable());
        $this->addIfMissing('branch_id', fn (Blueprint $table) => $table->unsignedBigInteger('branch_id')->nullable());
        $this->addIfMissing('applies_to', fn (Blueprint $table) => $table->string('applies_to', 20)->default('ALL'));
        $this->addIfMissing('personnel_id', fn (Blueprint $table) => $table->unsignedBigInteger('personnel_id')->nullable());
        $this->addIfMissing('position_reference_id', fn (Blueprint $table) => $table->unsignedBigInteger('position_reference_id')->nullable());
        $this->addIfMissing('service_category_reference_id', fn (Blueprint $table) => $table->unsignedBigInteger('service_category_reference_id')->nullable());
        $this->addIfMissing('rate_value', fn (Blueprint $table) => $table->decimal('rate_value', 12, 4)->nullable());
        $this->addIfMissing('priority', fn (Blueprint $table) => $table->integer('priority')->default(0));

        $this->makeLegacyColumnsNullable();

        DB::table('commission_rules')->update([
            'rule_name' => DB::raw('COALESCE(rule_name, code)'),
            'applies_to' => DB::raw("COALESCE(applies_to, 'ALL')"),
            'rate_value' => DB::raw('COALESCE(rate_value, rate_percentage)'),
            'basis' => 'STANDARD_SERVICE_PRICE',
            'priority' => DB::raw('COALESCE(priority, 0)'),
        ]);

        $this->ensureIndex(['business_id', 'applies_to', 'status'], 'commission_rules_business_applies_status_idx');
        $this->ensureIndex(['business_id', 'personnel_id', 'status'], 'commission_rules_business_personnel_status_idx');
        $this->ensureIndex(['business_id', 'position_reference_id', 'status'], 'commission_rules_business_position_status_idx');
        $this->ensureIndex(['business_id', 'service_category_reference_id', 'status'], 'commission_rules_business_category_status_idx');
        $this->ensureIndex(['business_id', 'branch_id', 'status'], 'commission_rules_business_branch_status_idx');
    }

    /**
     * Compatibility migration. Reversing it would risk deleting columns that are now part
     * of the canonical schema, so rollback intentionally leaves the reconciled schema intact.
     */
    public function down(): void
    {
    }

    private function addIfMissing(string $column, callable $definition): void
    {
        if (Schema::hasColumn('commission_rules', $column)) {
            return;
        }

        Schema::table('commission_rules', function (Blueprint $table) use ($definition) {
            $definition($table);
        });
    }

    private function makeLegacyColumnsNullable(): void
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

    private function ensureServiceForeignKeySupportIndex(): void
    {
        if (! Schema::hasColumn('commission_rules', 'service_id')) {
            return;
        }

        $hasSupportingIndex = collect(Schema::getIndexes('commission_rules'))
            ->contains(function (array $index) {
                $columns = array_values($index['columns'] ?? []);

                // MySQL only needs service_id to be the left-most indexed column
                // for the existing service_id foreign key to remain valid.
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
        $indexes = Schema::getIndexes('commission_rules');

        foreach ($indexes as $index) {
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

    private function ensureIndex(array $columns, string $name): void
    {
        foreach ($columns as $column) {
            if (! Schema::hasColumn('commission_rules', $column)) {
                return;
            }
        }

        $exists = collect(Schema::getIndexes('commission_rules'))
            ->contains(fn (array $index) => ($index['name'] ?? null) === $name);

        if (! $exists) {
            Schema::table('commission_rules', function (Blueprint $table) use ($columns, $name) {
                $table->index($columns, $name);
            });
        }
    }
};
