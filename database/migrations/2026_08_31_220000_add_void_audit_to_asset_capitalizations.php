<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_capitalizations', function (Blueprint $table): void {
            $table->foreignId('voided_by')->nullable()->after('reversal_reason')->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable()->after('voided_by');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('asset_capitalizations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
