<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_sales_teams', function (Blueprint $table): void {
            $table->id(); $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete(); $table->string('name');
            $table->foreignId('manager_id')->constrained('users')->restrictOnDelete(); $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete(); $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps(); $table->softDeletes(); $table->unique(['branch_id', 'name']);
        });
        Schema::create('crm_sales_team_members', function (Blueprint $table): void {
            $table->id(); $table->foreignId('team_id')->constrained('crm_sales_teams')->cascadeOnDelete(); $table->foreignId('user_id')->constrained('users')->restrictOnDelete(); $table->timestamps();
            $table->unique(['team_id', 'user_id']); $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_sales_team_members'); Schema::dropIfExists('crm_sales_teams');
    }
};
