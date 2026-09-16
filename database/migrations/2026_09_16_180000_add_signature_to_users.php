<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('signature_disk', 50)->nullable()->after('profile_image_path');
            $table->string('signature_path', 1024)->nullable()->after('signature_disk');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['signature_disk', 'signature_path']);
        });
    }
};
