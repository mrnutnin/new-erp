<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('profile_image_disk', 50)->nullable()->after('email');
            $table->string('profile_image_path', 1024)->nullable()->after('profile_image_disk');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['profile_image_disk', 'profile_image_path']);
        });
    }
};
