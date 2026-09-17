<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up():void
    {
        Schema::create('crm_customer_duplicate_resolutions',function(Blueprint $table):void{
            $table->id();
            $table->char('signature',64)->unique();
            $table->string('match_type',20);
            $table->string('match_key',255);
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down():void
    {
        Schema::dropIfExists('crm_customer_duplicate_resolutions');
    }
};
