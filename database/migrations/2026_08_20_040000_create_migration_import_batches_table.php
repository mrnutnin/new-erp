<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->string('template_version', 20);
            $table->string('source_system', 30);
            $table->string('original_filename');
            $table->string('checksum', 64);
            $table->enum('status', ['INVALID', 'VALIDATED', 'COMMITTED']);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->json('staged_rows');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('committed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'checksum']);
            $table->index(['type', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_import_batches');
    }
};
