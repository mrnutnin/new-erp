<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration { public function up(): void { Schema::table('sales_rfqs', function (Blueprint $table) { $table->foreignId('source_sales_intake_id')->nullable()->after('party_id')->constrained('sales_intakes')->restrictOnDelete(); $table->unique('source_sales_intake_id', 'sales_rfqs_source_intake_unique'); }); } public function down(): void { Schema::table('sales_rfqs', function (Blueprint $table) { $table->dropForeign(['source_sales_intake_id']); $table->dropUnique('sales_rfqs_source_intake_unique'); $table->dropColumn('source_sales_intake_id'); }); } };
