<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finance_document_sequences', fn (Blueprint $table) => $table->string('last_reset_key', 12)->nullable()->after('next_number'));
    }

    public function down(): void
    {
        Schema::table('finance_document_sequences', fn (Blueprint $table) => $table->dropColumn('last_reset_key'));
    }
};
