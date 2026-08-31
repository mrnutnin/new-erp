<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->foreignId('withholding_tax_code_id')->nullable()->after('tax_amount')
                ->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('withholding_rate', 8, 4)->unsigned()->default(0)->after('withholding_tax_code_id');
            $table->decimal('withholding_base', 18, 2)->unsigned()->default(0)->after('withholding_rate');
            $table->decimal('withholding_amount', 18, 2)->unsigned()->default(0)->after('withholding_base');
        });
    }

    public function down(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropForeign(['withholding_tax_code_id']);
            $table->dropColumn(['withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount']);
        });
    }
};
