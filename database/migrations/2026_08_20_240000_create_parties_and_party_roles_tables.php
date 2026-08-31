<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->enum('type', ['COMPANY', 'INDIVIDUAL'])->default('COMPANY');
            $table->char('tax_id', 13)->nullable();
            $table->char('branch_code', 5)->default('00000');
            $table->string('contact_name')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tax_id', 'branch_code']);
        });

        Schema::create('party_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->enum('role', ['CUSTOMER', 'SUPPLIER']);
            $table->foreignId('payment_term_id')->nullable()->constrained('finance_payment_terms')->nullOnDelete();
            $table->decimal('credit_limit', 18, 2)->unsigned()->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['party_id', 'role']);
            $table->index(['role', 'is_active', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_roles');
        Schema::dropIfExists('parties');
    }
};
