<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_purchase_variance_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_document_id')->constrained('purchase_documents')->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('revision')->default(0);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('acted_at');
            $table->string('reason', 500);
            $table->json('policy_snapshot');
            $table->json('match_snapshot');
            $table->char('evidence_hash', 64);
            $table->string('recovery_hint', 255)->nullable();
            $table->timestamps();

            $table->unique(['purchase_document_id', 'revision'], 'wms_purchase_variance_doc_revision_uq');
            $table->index(['purchase_document_id', 'status'], 'wms_purchase_variance_doc_status_idx');
            $table->index('evidence_hash', 'wms_purchase_variance_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_purchase_variance_approvals');
    }
};
