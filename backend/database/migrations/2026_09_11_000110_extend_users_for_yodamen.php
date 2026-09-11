<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('code', 50)->nullable()->unique()->after('id');
            $table->foreignId('business_id')->nullable()->after('code')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->foreignId('default_branch_id')->nullable()->after('business_id')->constrained('branches')->cascadeOnUpdate()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('default_branch_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('username', 100)->nullable()->unique()->after('role_id');
            $table->string('display_name')->nullable()->after('name');
            $table->string('status', 20)->default('ACTIVE')->index()->after('password');
            $table->boolean('force_reset')->default(false)->after('status');
            $table->unsignedSmallInteger('failed_attempts')->default(0)->after('force_reset');
            $table->timestamp('locked_until')->nullable()->after('failed_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropConstrainedForeignId('default_branch_id');
            $table->dropConstrainedForeignId('business_id');
            $table->dropColumn([
                'code',
                'username',
                'display_name',
                'status',
                'force_reset',
                'failed_attempts',
                'locked_until',
                'last_login_at',
            ]);
        });
    }
};
