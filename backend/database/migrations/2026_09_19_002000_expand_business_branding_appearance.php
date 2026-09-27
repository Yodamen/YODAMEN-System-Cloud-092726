<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_branding_settings', function (Blueprint $table) {
            $table->string('logo_source', 20)->default('NONE')->after('subtitle');
            $table->text('logo_path')->nullable()->after('logo_source');
            $table->string('font_family', 80)->default('INTER')->after('logo_url');
            $table->string('primary_color', 7)->default('#c8a64b')->after('font_family');
            $table->string('sidebar_color', 7)->default('#141414')->after('accent_color');
        });

        DB::table('business_branding_settings')
            ->whereNotNull('logo_url')
            ->where('logo_url', '<>', '')
            ->update(['logo_source' => 'URL']);

        DB::table('business_branding_settings')->update([
            'primary_color' => DB::raw('accent_color'),
        ]);
    }

    public function down(): void
    {
        Schema::table('business_branding_settings', function (Blueprint $table) {
            $table->dropColumn([
                'logo_source',
                'logo_path',
                'font_family',
                'primary_color',
                'sidebar_color',
            ]);
        });
    }
};
