<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pos_billing_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('document_number', 40);
            $table->date('document_date');
            $table->date('due_date')->nullable();
            $table->decimal('total_amount', 18, 2)->default(0);
            $table->enum('status', ['DRAFT', 'ISSUED', 'CANCELLED'])->default('DRAFT');
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['branch_id', 'document_number']);
            $table->index(['branch_id', 'status', 'document_date']);
        });

        Schema::create('pos_billing_note_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_note_id')->constrained('pos_billing_notes')->cascadeOnDelete();
            $table->foreignId('sales_document_id')->constrained('sales_documents')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();
            $table->unique(['billing_note_id', 'sales_document_id']);
            $table->unique('sales_document_id');
        });

        $column = DB::selectOne("SHOW COLUMNS FROM finance_document_sequences LIKE 'document_type'");
        if (str_starts_with(strtolower((string) ($column->Type ?? '')), 'enum(')) {
            preg_match_all("/'([^']+)'/", (string) $column->Type, $matches);
            $types = array_values(array_unique(array_merge($matches[1] ?? [], ['BILLING_NOTE'])));
            $enum = implode(',', array_map(fn (string $type): string => DB::getPdo()->quote($type), $types));
            DB::statement("ALTER TABLE finance_document_sequences MODIFY document_type ENUM({$enum}) NOT NULL");
        }
        DB::table('finance_document_sequences')->updateOrInsert(
            ['warehouse_id' => null, 'document_type' => 'BILLING_NOTE'],
            ['name' => 'ใบวางบิล', 'prefix' => 'BN', 'number_format' => '{PREFIX}-{BRANCH}-{YY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_billing_note_lines');
        Schema::dropIfExists('pos_billing_notes');
    }
};
