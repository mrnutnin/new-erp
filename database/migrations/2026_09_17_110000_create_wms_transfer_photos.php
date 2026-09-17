<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_transfer_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transfer_id')->constrained('wms_transfers')->restrictOnDelete();
            $table->string('stage', 20);
            $table->string('disk', 100);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('bytes');
            $table->char('checksum', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transfer_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_transfer_photos');
    }
};
