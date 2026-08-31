<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->enum('status', ['DRAFT', 'APPROVED', 'POSTED', 'VOID'])->default('DRAFT')->change();
            $table->foreignId('approved_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->string('approval_reason', 500)->nullable()->after('approved_at');
            $table->foreignId('voided_by')->nullable()->after('approval_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        DB::table('finance_settlements')->where('status', 'APPROVED')->update(['status' => 'DRAFT']);

        Schema::table('finance_settlements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['approved_at', 'approval_reason', 'voided_at', 'void_reason']);
            $table->enum('status', ['DRAFT', 'POSTED', 'VOID'])->default('DRAFT')->change();
        });
    }
};
