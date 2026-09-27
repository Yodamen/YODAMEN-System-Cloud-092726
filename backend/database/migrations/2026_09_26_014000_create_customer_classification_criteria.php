<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_type_criteria')) {
            Schema::create('customer_type_criteria', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 30);
                $table->string('name', 80);
                $table->unsignedInteger('min_completed_visits')->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true)->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'code']);
                $table->index(['business_id', 'active', 'min_completed_visits'], 'cust_type_active_visits_idx');
            });
        }

        if (! Schema::hasTable('customer_class_criteria')) {
            Schema::create('customer_class_criteria', function (Blueprint $table) {
                $table->id();
                $table->foreignId('business_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
                $table->string('code', 60);
                $table->string('name', 100);
                $table->decimal('min_lifetime_value', 14, 2)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('active')->default(true)->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->cascadeOnUpdate()->nullOnDelete();
                $table->timestamps();

                $table->unique(['business_id', 'code']);
                $table->index(['business_id', 'active', 'min_lifetime_value'], 'cust_class_active_ltv_idx');
            });
        }

        $defaultTypes = [
            ['code' => 'NEW', 'name' => 'New', 'min_completed_visits' => 0, 'sort_order' => 10],
            ['code' => 'RETURNEE', 'name' => 'Returnee', 'min_completed_visits' => 2, 'sort_order' => 20],
            ['code' => 'REGULAR', 'name' => 'Regular', 'min_completed_visits' => 3, 'sort_order' => 30],
            ['code' => 'LOYAL', 'name' => 'Loyal', 'min_completed_visits' => 11, 'sort_order' => 40],
        ];

        foreach (DB::table('businesses')->pluck('id') as $businessId) {
            foreach ($defaultTypes as $row) {
                DB::table('customer_type_criteria')->updateOrInsert(
                    ['business_id' => $businessId, 'code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'min_completed_visits' => $row['min_completed_visits'],
                        'sort_order' => $row['sort_order'],
                        'active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            $groupId = DB::table('reference_groups')
                ->where('business_id', $businessId)
                ->where('code', 'CUSTOMER_TYPE')
                ->value('id');

            if ($groupId) {
                foreach ($defaultTypes as $row) {
                    DB::table('reference_values')->updateOrInsert(
                        ['reference_group_id' => $groupId, 'code' => $row['code']],
                        [
                            'display_value' => $row['name'],
                            'sort_order' => $row['sort_order'],
                            'is_system' => true,
                            'active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            }
        }

        $permissions = [
            ['customer-type-criteria', 'VIEW', 'Customer Type Criteria - View'],
            ['customer-type-criteria', 'EDIT', 'Customer Type Criteria - Edit'],
            ['customer-class-criteria', 'VIEW', 'Customer Class Criteria - View'],
            ['customer-class-criteria', 'EDIT', 'Customer Class Criteria - Edit'],
        ];

        foreach ($permissions as [$module, $action, $name]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $module.'.'.$action],
                [
                    'module' => $module,
                    'action' => $action,
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', collect($permissions)->map(fn ($row) => $row[0].'.'.$row[1])->all())
            ->pluck('id');
        $roleIds = DB::table('roles')->whereIn('code', ['ROLE-SA', 'ROLE-MGR'])->pluck('id');

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
        $permissionIds = DB::table('permissions')
            ->whereIn('code', [
                'customer-type-criteria.VIEW',
                'customer-type-criteria.EDIT',
                'customer-class-criteria.VIEW',
                'customer-class-criteria.EDIT',
            ])
            ->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
            DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        }

        Schema::dropIfExists('customer_class_criteria');
        Schema::dropIfExists('customer_type_criteria');
    }
};
