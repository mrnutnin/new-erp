<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_document_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_setting_id')->constrained('company_settings')->restrictOnDelete();
            $table->string('document_type', 80);
            $table->string('name', 160);
            $table->boolean('is_default')->default(false);
            $table->enum('status', ['ACTIVE', 'ARCHIVED'])->default('ACTIVE');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_setting_id', 'document_type', 'status'], 'platform_template_scope_idx');
        });

        Schema::create('platform_document_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('platform_document_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->enum('status', ['DRAFT', 'PUBLISHED', 'RETIRED'])->default('DRAFT');
            $table->string('schema_version', 30)->default('1.0');
            $table->json('definition');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['template_id', 'version'], 'platform_template_version_unique');
            $table->index(['template_id', 'status'], 'platform_template_version_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_document_template_versions');
        Schema::dropIfExists('platform_document_templates');
    }
};
