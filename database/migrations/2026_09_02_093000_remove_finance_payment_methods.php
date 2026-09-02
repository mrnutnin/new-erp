<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('finance_payment_method_bank_accounts');
        Schema::dropIfExists('finance_payment_methods');
        DB::table('permissions')->whereIn('code', [
            'finance.payment-methods.view',
            'finance.payment-methods.create',
            'finance.payment-methods.update',
        ])->delete();
    }

    public function down(): void
    {
        Schema::create('finance_payment_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->enum('direction', ['BOTH', 'RECEIPT', 'PAYMENT'])->default('BOTH');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('finance_payment_method_bank_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_method_id')->constrained('finance_payment_methods')->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('finance_bank_accounts')->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['payment_method_id', 'bank_account_id'], 'finance_payment_method_bank_unique');
        });
    }
};
