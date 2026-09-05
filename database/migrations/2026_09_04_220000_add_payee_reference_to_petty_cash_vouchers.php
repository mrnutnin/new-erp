<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('finance_petty_cash_vouchers', function (Blueprint $table): void {
            $table->string('payee_type', 20)->default('OTHER')->after('document_date');
            $table->foreignId('payee_user_id')->nullable()->after('payee_type')->constrained('users')->nullOnDelete();
            $table->foreignId('payee_party_id')->nullable()->after('payee_user_id')->constrained('parties')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('finance_petty_cash_vouchers', function (Blueprint $table): void {
            $table->dropForeign(['payee_user_id']);
            $table->dropForeign(['payee_party_id']);
            $table->dropColumn(['payee_type', 'payee_user_id', 'payee_party_id']);
        });
    }
};
