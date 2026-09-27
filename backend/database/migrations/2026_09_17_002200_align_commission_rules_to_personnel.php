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

        if (! Schema::hasColumn('commission_rules', 'personnel_id')) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->unsignedBigInteger('personnel_id')->nullable();
            });
        }

        if (Schema::hasColumn('commission_rules', 'employee_id')) {
            DB::table('commission_rules')
                ->whereNotNull('employee_id')
                ->whereNull('personnel_id')
                ->update(['personnel_id' => DB::raw('employee_id')]);

            $this->dropForeignKeyForColumn('employee_id');

            Schema::table('commission_rules', function (Blueprint $table) {
                $table->dropColumn('employee_id');
            });
        }

        $this->ensurePersonnelIndex();
    }

    /**
     * Personnel is the canonical identifier. This compatibility migration is intentionally
     * not destructively reversed back to employee_id.
     */
    public function down(): void
    {
    }

    private function dropForeignKeyForColumn(string $column): void
    {
        $foreign = collect(Schema::getForeignKeys('commission_rules'))
            ->first(function (array $key) use ($column) {
                return in_array($column, $key['columns'] ?? [], true);
            });

        if ($foreign && ! empty($foreign['name'])) {
            Schema::table('commission_rules', function (Blueprint $table) use ($foreign) {
                $table->dropForeign($foreign['name']);
            });
        }
    }

    private function ensurePersonnelIndex(): void
    {
        if (! Schema::hasColumn('commission_rules', 'personnel_id')) {
            return;
        }

        $exists = collect(Schema::getIndexes('commission_rules'))
            ->contains(fn (array $index) => ($index['name'] ?? null) === 'commission_rules_business_personnel_status_idx');

        if (! $exists) {
            Schema::table('commission_rules', function (Blueprint $table) {
                $table->index(
                    ['business_id', 'personnel_id', 'status'],
                    'commission_rules_business_personnel_status_idx'
                );
            });
        }
    }
};
