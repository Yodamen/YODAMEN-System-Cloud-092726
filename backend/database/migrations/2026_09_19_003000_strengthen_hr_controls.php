<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('personnel', 'paid_leave_entitled')) {
            Schema::table('personnel', function (Blueprint $table) {
                // Preserve the pre-control behavior for existing personnel: paid leave was effectively available to everyone.
                $table->boolean('paid_leave_entitled')->default(true)->after('commission_eligible');
            });
        }
        if (! Schema::hasColumn('personnel', 'compensation_effective_from')) {
            Schema::table('personnel', function (Blueprint $table) {
                $table->date('compensation_effective_from')->nullable()->after('guaranteed_daily_pay');
            });
        }

        if (! Schema::hasColumn('leave_requests', 'paid_leave_approved')) {
            Schema::table('leave_requests', function (Blueprint $table) {
                $table->boolean('paid_leave_approved')->default(false)->after('approved_units');
            });

            // Backfill existing approved paid leaves using the entitlement state that existed at migration time.
            DB::table('leave_requests')
                ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
                ->join('personnel', 'personnel.id', '=', 'leave_requests.personnel_id')
                ->where('leave_requests.request_type', 'LEAVE')
                ->where('leave_requests.status', 'APPROVED')
                ->where('leave_types.is_paid', true)
                ->where('personnel.paid_leave_entitled', true)
                ->update(['leave_requests.paid_leave_approved' => true]);
        }

        if (! Schema::hasTable('personnel_compensation_terms')) {
            Schema::create('personnel_compensation_terms', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('personnel_id')->constrained('personnel')->cascadeOnUpdate()->restrictOnDelete();
                $table->date('effective_from');
                $table->date('effective_until')->nullable();
                $table->foreignId('compensation_rule_reference_id')->constrained('reference_values')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('hourly_rate', 12, 2)->default(0);
                $table->boolean('ot_entitled')->default(false);
                $table->decimal('ot_multiplier', 6, 3)->nullable();
                $table->decimal('guaranteed_daily_pay', 12, 2)->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['personnel_id', 'effective_from'], 'personnel_comp_term_effective_uq');
                $table->index(['business_id', 'personnel_id', 'effective_from'], 'personnel_comp_term_lookup_idx');
            });

            $rows = DB::table('personnel')->get([
                'id', 'business_id', 'hire_date', 'compensation_rule_reference_id', 'hourly_rate',
                'ot_entitled', 'ot_multiplier', 'guaranteed_daily_pay', 'created_by',
            ]);

            foreach ($rows as $row) {
                $effectiveFrom = $row->hire_date ?: '1900-01-01';
                DB::table('personnel_compensation_terms')->insert([
                    'business_id' => $row->business_id,
                    'personnel_id' => $row->id,
                    'effective_from' => $effectiveFrom,
                    'effective_until' => null,
                    'compensation_rule_reference_id' => $row->compensation_rule_reference_id,
                    'hourly_rate' => $row->hourly_rate,
                    'ot_entitled' => $row->ot_entitled,
                    'ot_multiplier' => $row->ot_multiplier,
                    'guaranteed_daily_pay' => $row->guaranteed_daily_pay,
                    'created_by' => $row->created_by,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('personnel')->where('id', $row->id)->update([
                    'compensation_effective_from' => $effectiveFrom,
                ]);
            }
        }

        if (! Schema::hasTable('payroll_cash_advance_waivers')) {
            Schema::create('payroll_cash_advance_waivers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnUpdate()->restrictOnDelete();
                $table->foreignId('payroll_line_id')->nullable()->constrained('payroll_lines')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('cash_advance_id')->constrained('employee_cash_advances')->cascadeOnUpdate()->restrictOnDelete();
                $table->decimal('scheduled_amount', 12, 2)->default(0);
                $table->decimal('waived_amount', 12, 2)->default(0);
                $table->string('status', 20)->default('WAIVED')->index(); // WAIVED | REVOKED
                $table->text('reason');
                $table->foreignId('waived_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
                $table->dateTime('waived_at');
                $table->foreignId('revoked_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('revoked_at')->nullable();
                $table->text('revocation_reason')->nullable();
                $table->timestamps();

                $table->unique(['payroll_run_id', 'cash_advance_id'], 'payroll_ca_waiver_run_advance_uq');
                $table->index(['business_id', 'payroll_run_id', 'status'], 'payroll_ca_waiver_lookup_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_cash_advance_waivers');
        Schema::dropIfExists('personnel_compensation_terms');

        if (Schema::hasColumn('leave_requests', 'paid_leave_approved')) {
            Schema::table('leave_requests', fn (Blueprint $table) => $table->dropColumn('paid_leave_approved'));
        }

        if (Schema::hasColumn('personnel', 'paid_leave_entitled')) {
            Schema::table('personnel', fn (Blueprint $table) => $table->dropColumn('paid_leave_entitled'));
        }
        if (Schema::hasColumn('personnel', 'compensation_effective_from')) {
            Schema::table('personnel', fn (Blueprint $table) => $table->dropColumn('compensation_effective_from'));
        }
    }
};
