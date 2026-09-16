<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->char('tax_branch_code', 5)->nullable()->after('name');
            $table->text('tax_address')->nullable()->after('tax_branch_code');
        });

        Schema::table('company_settings', function (Blueprint $table): void {
            $table->dropColumn('tax_branch_code');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table): void {
            $table->char('tax_branch_code', 5)->nullable()->after('tax_id');
        });

        Schema::table('branches', function (Blueprint $table): void {
            $table->dropColumn(['tax_branch_code', 'tax_address']);
        });
    }
};
