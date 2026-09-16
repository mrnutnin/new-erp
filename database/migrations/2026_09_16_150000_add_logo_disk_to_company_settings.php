<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_settings', 'logo_disk')) {
            Schema::table('company_settings', function (Blueprint $table): void {
                $table->string('logo_disk', 50)->nullable()->after('company_address');
            });
        }
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn('logo_disk');
        });
    }
};
