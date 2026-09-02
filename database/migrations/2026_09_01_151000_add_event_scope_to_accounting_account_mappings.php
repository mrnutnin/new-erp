<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_account_mappings', function (Blueprint $table) {
            $table->dropUnique('accounting_account_mappings_key_unique');
            $table->string('event_code', 80)->nullable()->after('key');
            $table->unsignedInteger('version')->default(1)->after('is_active');
            $table->unique(['event_code', 'key'], 'accounting_mapping_event_role_unique');
            $table->index(['event_code', 'is_active'], 'accounting_mapping_event_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_account_mappings', function (Blueprint $table) {
            $table->dropIndex('accounting_mapping_event_active_index');
            $table->dropUnique('accounting_mapping_event_role_unique');
            $table->dropColumn(['event_code', 'version']);
            $table->unique('key');
        });
    }
};
