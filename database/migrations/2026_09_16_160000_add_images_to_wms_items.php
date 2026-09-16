<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_items', function (Blueprint $table): void {
            $table->string('cover_image_disk', 50)->nullable()->after('name');
            $table->string('cover_image_path', 1024)->nullable()->after('cover_image_disk');
            $table->json('additional_images')->nullable()->after('cover_image_path');
        });
    }

    public function down(): void
    {
        Schema::table('wms_items', function (Blueprint $table): void {
            $table->dropColumn(['cover_image_disk', 'cover_image_path', 'additional_images']);
        });
    }
};
