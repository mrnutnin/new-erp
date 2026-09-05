<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_employee_advances', function (Blueprint $table): void {
            $table->index(['warehouse_id', 'status', 'due_date'], 'finance_employee_advances_work_due_idx');
        });
        Schema::table('finance_petty_cash_voucher_lines', function (Blueprint $table): void {
            $table->index('receipt_reference', 'finance_petty_cash_voucher_lines_receipt_idx');
        });
        Schema::table('finance_employee_advance_clearing_lines', function (Blueprint $table): void {
            $table->index('receipt_reference', 'finance_employee_advance_clearing_lines_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::table('finance_employee_advance_clearing_lines', function (Blueprint $table): void {
            $table->dropIndex('finance_employee_advance_clearing_lines_receipt_idx');
        });
        Schema::table('finance_petty_cash_voucher_lines', function (Blueprint $table): void {
            $table->dropIndex('finance_petty_cash_voucher_lines_receipt_idx');
        });
        Schema::table('finance_employee_advances', function (Blueprint $table): void {
            $table->dropIndex('finance_employee_advances_work_due_idx');
        });
    }
};
