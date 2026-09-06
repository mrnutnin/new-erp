<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fresh installs create the document table later in this migration batch;
        // the columns are included in that table definition below.
        if (! Schema::hasTable('wms_inventory_adjustment_documents')) {
            return;
        }

        Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->enum('reversal_status', ['NONE', 'REVERSED'])->default('NONE')->after('status');
            $table->foreignId('reversed_by')->nullable()->after('posted_by')->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable()->after('reversed_by');
            $table->string('reversal_reason', 500)->nullable()->after('reversed_at');
            $table->unsignedInteger('reversal_revision')->default(0)->after('reversal_reason');
            $table->unique(['id', 'reversal_revision'], 'wms_adj_documents_reversal_revision_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('wms_inventory_adjustment_documents')) {
            return;
        }

        Schema::table('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->dropUnique('wms_adj_documents_reversal_revision_unique');
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversal_status', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_revision']);
        });
    }
};
