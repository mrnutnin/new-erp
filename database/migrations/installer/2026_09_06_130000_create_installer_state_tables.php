<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('installation_sessions')) {
            Schema::create('installation_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('status', 30)->default('IN_PROGRESS')->index();
                $table->unsignedTinyInteger('progress')->default(0);
                $table->unsignedBigInteger('started_by')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('go_live_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('installation_steps')) {
            Schema::create('installation_steps', function (Blueprint $table) {
                $table->id();
                $table->foreignId('installation_session_id')->constrained('installation_sessions')->cascadeOnDelete();
                $table->string('step_code', 80);
                $table->string('status', 30)->default('NOT_STARTED');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['installation_session_id', 'step_code'], 'installer_steps_session_step_unique');
            });
        }

        if (! Schema::hasTable('installation_checklists')) {
            Schema::create('installation_checklists', function (Blueprint $table) {
                $table->id();
                $table->foreignId('installation_session_id')->constrained('installation_sessions')->cascadeOnDelete();
                $table->string('step_code', 80);
                $table->string('checklist_code', 100);
                $table->string('type', 20)->default('REQUIRED');
                $table->string('status', 30)->default('NOT_STARTED');
                $table->unsignedBigInteger('completed_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['installation_session_id', 'checklist_code'], 'installer_checklists_session_code_unique');
            });
        }

        if (! Schema::hasTable('installation_logs')) {
            Schema::create('installation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('installation_session_id')->nullable()->constrained('installation_sessions')->nullOnDelete();
                $table->string('step_code', 80)->nullable();
                $table->string('action', 100);
                $table->json('old_value')->nullable();
                $table->json('new_value')->nullable();
                $table->string('status', 30)->default('SUCCESS');
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('technical_detail')->nullable();
                $table->timestamp('created_at')->useCurrent();
                $table->index(['installation_session_id', 'created_at'], 'installer_logs_session_created_idx');
            });
        }

        if (! Schema::hasTable('system_seed_versions')) {
            Schema::create('system_seed_versions', function (Blueprint $table) {
                $table->id();
                $table->string('seed_code', 100)->unique('installer_seed_code_unique');
                $table->string('version', 30);
                $table->timestamp('installed_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->json('metadata')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('system_seed_versions');
        Schema::dropIfExists('installation_logs');
        Schema::dropIfExists('installation_checklists');
        Schema::dropIfExists('installation_steps');
        Schema::dropIfExists('installation_sessions');
    }
};
