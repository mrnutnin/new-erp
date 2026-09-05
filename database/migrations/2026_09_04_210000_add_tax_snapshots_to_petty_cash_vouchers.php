<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('finance_petty_cash_vouchers', function (Blueprint $table): void {
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0)->after('total_amount');
            $table->decimal('withholding_amount', 18, 2)->unsigned()->default(0)->after('tax_amount');
            $table->decimal('net_amount', 18, 2)->unsigned()->default(0)->after('withholding_amount');
        });
        Schema::table('finance_petty_cash_voucher_lines', function (Blueprint $table): void {
            $table->foreignId('tax_code_id')->nullable()->after('amount')->constrained('tax_codes')->nullOnDelete();
            $table->string('tax_code_code', 30)->nullable()->after('tax_code_id');
            $table->decimal('tax_rate', 7, 4)->nullable()->after('tax_code_code');
            $table->decimal('tax_base', 18, 2)->unsigned()->default(0)->after('tax_rate');
            $table->decimal('tax_amount', 18, 2)->unsigned()->default(0)->after('tax_base');
            $table->foreignId('withholding_tax_code_id')->nullable()->after('tax_amount')->constrained('tax_codes')->nullOnDelete();
            $table->string('withholding_tax_code', 30)->nullable()->after('withholding_tax_code_id');
            $table->decimal('withholding_rate', 7, 4)->nullable()->after('withholding_tax_code');
            $table->decimal('withholding_base', 18, 2)->unsigned()->default(0)->after('withholding_rate');
            $table->decimal('withholding_amount', 18, 2)->unsigned()->default(0)->after('withholding_base');
        });
    }
    public function down(): void
    {
        Schema::table('finance_petty_cash_voucher_lines', function (Blueprint $table): void {
            $table->dropForeign(['tax_code_id']);
            $table->dropForeign(['withholding_tax_code_id']);
            $table->dropColumn(['tax_code_id', 'tax_code_code', 'tax_rate', 'tax_base', 'tax_amount', 'withholding_tax_code_id', 'withholding_tax_code', 'withholding_rate', 'withholding_base', 'withholding_amount']);
        });
        Schema::table('finance_petty_cash_vouchers', function (Blueprint $table): void {
            $table->dropColumn(['tax_amount', 'withholding_amount', 'net_amount']);
        });
    }
};
