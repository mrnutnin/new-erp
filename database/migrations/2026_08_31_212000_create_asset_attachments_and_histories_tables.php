<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->enum('subject_type', ['ASSET', 'MAINTENANCE', 'IMPAIRMENT', 'DISPOSAL']);
            $table->unsignedBigInteger('subject_id');
            $table->enum('file_type', ['PHOTO', 'INVOICE', 'WARRANTY', 'INSURANCE', 'REPAIR_REPORT', 'DISPOSAL_EVIDENCE', 'OTHER'])->default('OTHER');
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('bytes');
            $table->char('checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'subject_type', 'subject_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['checksum']);
        });

        Schema::create('asset_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 100);
            $table->timestamp('occurred_at');
            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_document_number', 100)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->foreignId('old_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('new_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('old_location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('new_location_id')->nullable()->constrained('asset_locations')->restrictOnDelete();
            $table->foreignId('old_custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('new_custodian_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['asset_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->index(['event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_histories');
        Schema::dropIfExists('asset_attachments');
    }
};
