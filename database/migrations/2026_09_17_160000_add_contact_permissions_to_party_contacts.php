<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('party_contacts', function (Blueprint $table): void {
            $table->string('contact_permission_status', 20)->default('UNKNOWN')->after('preferred_channel');
            $table->string('lawful_basis', 30)->nullable()->after('contact_permission_status');
            $table->boolean('allow_phone')->default(false)->after('lawful_basis');
            $table->boolean('allow_email')->default(false)->after('allow_phone');
            $table->boolean('allow_line')->default(false)->after('allow_email');
            $table->timestamp('permission_recorded_at')->nullable()->after('allow_line');
            $table->foreignId('permission_recorded_by')->nullable()->after('permission_recorded_at')->constrained('users')->nullOnDelete();
            $table->string('permission_note', 500)->nullable()->after('permission_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('party_contacts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('permission_recorded_by');
            $table->dropColumn(['contact_permission_status', 'lawful_basis', 'allow_phone', 'allow_email', 'allow_line', 'permission_recorded_at', 'permission_note']);
        });
    }
};
