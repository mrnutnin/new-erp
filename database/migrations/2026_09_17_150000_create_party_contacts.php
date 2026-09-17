<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('party_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->restrictOnDelete();
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('decision_role', 30)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('line_id', 100)->nullable();
            $table->string('preferred_channel', 20)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['party_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('party_contacts');
    }
};
