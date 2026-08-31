<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->enum('business_profile', ['TRADING', 'MANUFACTURING'])
                ->nullable()
                ->after('date_format');
            $table->boolean('production_enabled')
                ->nullable()
                ->after('business_profile');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn(['business_profile', 'production_enabled']);
        });
    }
};
