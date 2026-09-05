<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_employee_advance_clearings', function (Blueprint $table): void {
            $table->foreignId('voided_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('finance_employee_advance_clearings', function (Blueprint $table): void {
            $table->dropForeign(['voided_by']);
            $table->dropColumn(['voided_by', 'voided_at', 'void_reason']);
        });
    }
};
