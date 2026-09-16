<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('position')->nullable()->after('employee_code');
            $table->string('signature_checksum', 64)->nullable()->after('signature_path');
            $table->string('signature_mime_type', 100)->nullable()->after('signature_checksum');
        });

        Schema::create('document_signature_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('role', 50);
            $table->string('action', 150);
            $table->foreignId('audit_log_id')->nullable()->unique()->constrained('audit_logs')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_name')->nullable();
            $table->string('signer_position')->nullable();
            $table->dateTime('signed_at');
            $table->string('signature_disk', 50)->nullable();
            $table->string('signature_path', 1024)->nullable();
            $table->string('signature_checksum', 64)->nullable();
            $table->string('signature_mime_type', 100)->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id', 'role', 'id'], 'document_signature_subject_role_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signature_snapshots');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['position', 'signature_checksum', 'signature_mime_type']);
        });
    }
};
