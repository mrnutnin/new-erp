<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('document_date');
        });
    }

    public function down(): void
    {
        Schema::table('pos_physical_sales', function (Blueprint $table): void {
            $table->dropColumn('due_date');
        });
    }
};
