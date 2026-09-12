<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_cost_revaluation_batches')) {
            Schema::create('wms_cost_revaluation_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('idempotency_key', 180)->unique('wms_reval_batch_key_uq');
                $table->string('source_document_type', 64);
                $table->unsignedBigInteger('source_document_id');
                $table->string('source_document_reference', 120)->nullable();
                $table->date('document_date')->nullable();
                $table->unsignedInteger('source_revision')->default(0);
                $table->string('status', 32)->default('QUEUED');
                $table->unsignedInteger('expected_root_lines')->default(0);
                $table->unsignedInteger('resolved_root_lines')->default(0);
                $table->unsignedInteger('expected_partitions')->default(0);
                $table->unsignedInteger('completed_partitions')->default(0);
                $table->unsignedInteger('failed_partitions')->default(0);
                $table->json('blockers')->nullable();
                $table->json('trigger_snapshot');
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('heartbeat_at')->nullable();
                $table->timestamps();
                $table->index(['source_document_type', 'source_document_id', 'source_revision'], 'wms_reval_batch_source_idx');
                $table->index(['status', 'heartbeat_at'], 'wms_reval_batch_status_idx');
            });
        }

        if (Schema::hasTable('wms_cost_revaluation_runs')) {
            Schema::table('wms_cost_revaluation_runs', function (Blueprint $table): void {
                if (! Schema::hasColumn('wms_cost_revaluation_runs', 'batch_id')) {
                    $table->foreignId('batch_id')->nullable()->after('id')->constrained('wms_cost_revaluation_batches')->restrictOnDelete();
                }
                if (! Schema::hasColumn('wms_cost_revaluation_runs', 'partition_key')) {
                    $table->string('partition_key', 180)->nullable()->after('batch_id');
                }
            });
            if (! collect(Schema::getIndexes('wms_cost_revaluation_runs'))->contains('name', 'wms_reval_run_batch_part_uq')) {
                Schema::table('wms_cost_revaluation_runs', fn (Blueprint $table) => $table->unique(['batch_id', 'partition_key'], 'wms_reval_run_batch_part_uq'));
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wms_cost_revaluation_runs')) {
            Schema::table('wms_cost_revaluation_runs', function (Blueprint $table): void {
                if (collect(Schema::getIndexes('wms_cost_revaluation_runs'))->contains('name', 'wms_reval_run_batch_part_uq')) {
                    $table->dropUnique('wms_reval_run_batch_part_uq');
                }
                $table->dropConstrainedForeignId('batch_id');
                $table->dropColumn('partition_key');
            });
        }
        Schema::dropIfExists('wms_cost_revaluation_batches');
    }
};
