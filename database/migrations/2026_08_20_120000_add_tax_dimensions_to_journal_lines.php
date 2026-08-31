<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->foreignId('tax_code_id')->nullable()->after('account_id')->constrained('tax_codes')->restrictOnDelete();
            $table->decimal('tax_base', 20, 2)->nullable()->after('credit');
            $table->decimal('tax_amount', 20, 2)->nullable()->after('tax_base');
            $table->date('tax_point_date')->nullable()->after('tax_amount');
            $table->date('tax_settlement_date')->nullable()->after('tax_point_date');
            $table->index(['tax_code_id', 'tax_point_date']);
        });
    }

    public function down(): void
    {
        Schema::table('journal_entry_lines', function (Blueprint $table) {
            $table->dropForeign(['tax_code_id']);
            $table->dropIndex(['tax_code_id', 'tax_point_date']);
            $table->dropColumn(['tax_code_id', 'tax_base', 'tax_amount', 'tax_point_date', 'tax_settlement_date']);
        });
    }
};
