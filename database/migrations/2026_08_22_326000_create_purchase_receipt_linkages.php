<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_document_lines', function (Blueprint $table): void {
            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->after('account_id')
                ->constrained('purchase_order_lines', 'id', 'pdl_purchase_order_line_fk')
                ->restrictOnDelete();
            $table->index('purchase_order_line_id', 'pdl_purchase_order_line_ix');
        });

        Schema::create('purchase_document_receipt_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_document_line_id')
                ->constrained('purchase_document_lines', 'id', 'pdra_document_line_fk')
                ->cascadeOnDelete();
            $table->foreignId('goods_receipt_line_id')
                ->constrained('goods_receipt_lines', 'id', 'pdra_receipt_line_fk')
                ->restrictOnDelete();
            $table->decimal('allocated_quantity', 20, 8)->unsigned();
            $table->decimal('allocated_amount', 20, 8)->unsigned()->default(0);
            $table->string('idempotency_key', 180);
            $table->timestamps();

            $table->unique(['purchase_document_line_id', 'goods_receipt_line_id'], 'pdra_document_receipt_uq');
            $table->unique('idempotency_key', 'pdra_idempotency_uq');
            $table->index(['goods_receipt_line_id', 'purchase_document_line_id'], 'pdra_receipt_document_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_document_receipt_allocations');

        Schema::table('purchase_document_lines', function (Blueprint $table): void {
            $table->dropForeign('pdl_purchase_order_line_fk');
            $table->dropIndex('pdl_purchase_order_line_ix');
            $table->dropColumn('purchase_order_line_id');
        });
    }
};
