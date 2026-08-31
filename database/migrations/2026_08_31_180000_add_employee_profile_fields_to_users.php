<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_code', 100)->nullable()->unique()->after('username');
            $table->foreignId('primary_branch_id')->nullable()->after('is_active')->constrained('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('primary_branch_id');
            $table->dropUnique(['employee_code']);
            $table->dropColumn('employee_code');
        });
    }
};
