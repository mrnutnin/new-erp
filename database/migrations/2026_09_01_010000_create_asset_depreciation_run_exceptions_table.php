<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_depreciation_run_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('asset_depreciation_run_id');
            $table->foreign('asset_depreciation_run_id', 'asset_dep_run_exception_run_fk')->references('id')->on('asset_depreciation_runs')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->string('asset_number', 40);
            $table->string('asset_name');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['asset_depreciation_run_id', 'asset_id'], 'asset_depreciation_exception_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_run_exceptions');
    }
};
