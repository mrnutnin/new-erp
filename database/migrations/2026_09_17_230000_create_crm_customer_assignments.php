<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('crm_customer_assignments',function(Blueprint $table):void{
            $table->id();
            $table->foreignId('party_id')->constrained('parties')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('crm_sales_teams')->nullOnDelete();
            $table->string('territory',100)->nullable();
            $table->foreignId('backup_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['party_id','branch_id']);
            $table->index(['branch_id','owner_id']);
        });
    }
    public function down():void { Schema::dropIfExists('crm_customer_assignments'); }
};
