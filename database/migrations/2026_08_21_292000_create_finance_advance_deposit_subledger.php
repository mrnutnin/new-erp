<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_advance_deposits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->enum('party_type', ['CUSTOMER', 'SUPPLIER']);
            $table->enum('direction', ['RECEIPT', 'PAYMENT']);
            $table->enum('instrument_type', ['ADVANCE', 'DEPOSIT']);
            $table->foreignId('source_settlement_id')->nullable()->unique()->constrained('finance_settlements')->restrictOnDelete();
            $table->string('document_number', 100);
            $table->date('document_date');
            $table->date('posting_date')->nullable();
            $table->decimal('original_amount', 18, 2)->unsigned();
            $table->decimal('applied_amount', 18, 2)->unsigned()->default(0);
            $table->enum('status', ['DRAFT', 'POSTED', 'PARTIAL', 'APPLIED', 'VOID', 'REVERSED'])->default('DRAFT');
            $table->char('idempotency_key', 64)->unique();
            $table->foreignId('reversal_of_id')->nullable()->constrained('finance_advance_deposits')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->char('reversal_key', 64)->nullable()->unique();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'party_type', 'party_id', 'status'], 'finance_advances_party_status_idx');
            $table->index(['direction', 'instrument_type', 'posting_date'], 'finance_advances_type_date_idx');
        });

        Schema::create('finance_advance_deposit_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('advance_deposit_id')->constrained('finance_advance_deposits')->restrictOnDelete();
            $table->foreignId('open_item_id')->constrained('finance_open_items')->restrictOnDelete();
            $table->date('application_date');
            $table->decimal('amount', 18, 2)->unsigned();
            $table->string('source_type', 30);
            $table->string('source_id', 100);
            $table->char('idempotency_key', 64)->unique();
            $table->char('application_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->date('reversal_date')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->char('reversal_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['advance_deposit_id', 'application_date'], 'finance_adv_applications_advance_date_idx');
            $table->index(['open_item_id', 'application_date'], 'finance_adv_applications_item_date_idx');
            $table->index(['source_type', 'source_id'], 'finance_adv_applications_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_advance_deposit_applications');
        Schema::dropIfExists('finance_advance_deposits');
    }
};
