<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reversal_approval_requests')) {
            Schema::create('reversal_approval_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 80)->unique();
                $table->string('module', 80)->index();
                $table->string('source_type', 80)->index();
                $table->unsignedBigInteger('source_id');
                $table->string('source_code', 100)->nullable();
                $table->string('status', 30)->default('PENDING')->index();
                $table->text('reason');
                $table->foreignId('requested_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('requested_at');
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->dateTime('reviewed_at')->nullable();
                $table->text('review_notes')->nullable();
                $table->timestamps();
                $table->index(['business_id','source_type','source_id','status'], 'reversal_approval_source_status_idx');
            });
        }

        $permissions = [
            ['petty-cash', 'APPROVE_REVERSAL'],
            ['cash-management', 'APPROVE_REVERSAL'],
            ['cash-deposits', 'APPROVE_REVERSAL'],
            ['payroll', 'APPROVE_REVERSAL'],
        ];
        foreach ($permissions as [$module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['module' => $module, 'action' => $action],
                [
                    'code' => $module.'.'.$action,
                    'name' => str($module)->replace('-', ' ')->title().' - '.str($action)->replace('_', ' ')->title(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', collect($permissions)->map(fn ($row) => $row[0].'.'.$row[1])->all())
            ->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA','ROLE-MGR'])->pluck('id');
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reversal_approval_requests');
    }
};
