<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_intakes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $t->string('document_number', 40);
            $t->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            foreach (['party_code', 'party_name', 'party_tax_id', 'party_branch_code'] as $c) {
                $t->string($c, 255)->nullable();
            }$t->text('party_address')->nullable();
            $t->date('document_date');
            $t->string('source', 100)->nullable();
            $t->string('description', 500)->nullable();
            $t->enum('status', ['DRAFT', 'COMPLETED', 'CANCELLED'])->default('DRAFT');
            $t->boolean('requires_rfq')->default(false);
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['warehouse_id', 'document_number']);
            $t->index(['warehouse_id', 'status', 'document_date']);
        });
        Schema::create('sales_intake_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_intake_id')->constrained('sales_intakes')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_number');
            $t->foreignId('item_id')->nullable()->constrained('wms_items')->restrictOnDelete();
            $t->foreignId('uom_id')->nullable()->constrained('wms_uoms')->restrictOnDelete();
            $t->string('description', 500)->nullable();
            $t->decimal('quantity', 18, 4);
            $t->decimal('standard_unit_price', 18, 4)->nullable();
            $t->decimal('requested_unit_price', 18, 4)->nullable();
            $t->json('item_snapshot')->nullable();
            $t->json('uom_snapshot')->nullable();
            $t->timestamps();
            $t->unique(['sales_intake_id', 'line_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_intake_lines');
        Schema::dropIfExists('sales_intakes');
    }
};
