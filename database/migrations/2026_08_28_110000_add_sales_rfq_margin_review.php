<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('sales_rfqs')->whereIn('status', ['DRAFT', 'SENT'])->update(['status' => 'WAIT']);
        DB::table('sales_rfqs')->where('status', 'CLOSED')->update(['status' => 'APPROVED']);
        DB::statement("ALTER TABLE sales_rfqs MODIFY status ENUM('WAIT','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'WAIT'");
        Schema::table('sales_rfqs', function (Blueprint $table): void {
            $table->foreignId('reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->string('review_reason', 500)->nullable()->after('reviewed_at');
        });
        Schema::table('sales_rfq_lines', function (Blueprint $table): void {
            $table->decimal('proposed_unit_price', 18, 4)->default(0)->after('quantity');
            $table->decimal('proposed_discount_amount', 18, 2)->default(0)->after('proposed_unit_price');
            $table->decimal('estimated_unit_cost', 18, 4)->nullable()->after('proposed_discount_amount');
            $table->decimal('estimated_cost_amount', 18, 2)->nullable()->after('estimated_unit_cost');
            $table->decimal('estimated_margin_amount', 18, 2)->nullable()->after('estimated_cost_amount');
            $table->decimal('estimated_margin_percent', 9, 4)->nullable()->after('estimated_margin_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales_rfq_lines', function (Blueprint $table): void {$table->dropColumn(['proposed_unit_price', 'proposed_discount_amount', 'estimated_unit_cost', 'estimated_cost_amount', 'estimated_margin_amount', 'estimated_margin_percent']);});
        Schema::table('sales_rfqs', function (Blueprint $table): void {$table->dropConstrainedForeignId('reviewed_by'); $table->dropColumn(['reviewed_at', 'review_reason']);});
        DB::statement("ALTER TABLE sales_rfqs MODIFY status ENUM('DRAFT','SENT','CLOSED','CANCELLED') NOT NULL DEFAULT 'DRAFT'");
    }
};
