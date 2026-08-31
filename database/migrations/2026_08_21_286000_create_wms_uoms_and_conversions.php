<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_uoms', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('wms_uom_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->foreignId('to_uom_id')->constrained('wms_uoms')->restrictOnDelete();
            $table->decimal('factor', 18, 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['from_uom_id', 'to_uom_id']);
        });
        Schema::table('wms_items', function (Blueprint $table) {
            $table->foreignId('base_uom_id')->nullable()->after('base_uom')->constrained('wms_uoms')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wms_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('base_uom_id'));
        Schema::dropIfExists('wms_uom_conversions');
        Schema::dropIfExists('wms_uoms');
    }
};
