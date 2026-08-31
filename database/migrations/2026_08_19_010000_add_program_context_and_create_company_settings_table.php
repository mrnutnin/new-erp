<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('requires_warehouse')->default(true)->after('description');
            $table->string('entry_route')->default('dashboard')->after('requires_warehouse');
        });

        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name');
            $table->string('tax_id', 13)->nullable()->index();
            $table->string('locale', 10)->default('th');
            $table->string('timezone', 50)->default('Asia/Bangkok');
            $table->char('base_currency', 3)->default('THB');
            $table->string('date_format', 20)->default('d/m/Y');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');

        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['requires_warehouse', 'entry_route']);
        });
    }
};
