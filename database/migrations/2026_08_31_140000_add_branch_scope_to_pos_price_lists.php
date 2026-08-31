<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_price_lists', function (Blueprint $table): void {
            $table->foreignId('branch_id')->nullable()->after('currency')->constrained()->restrictOnDelete();
            $table->index(['branch_id', 'is_active', 'effective_from', 'effective_to'], 'pos_price_lists_branch_scope_idx');
        });

        $branchId = DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');
        if (! $branchId) {
            throw new RuntimeException('Cannot scope Price Lists without an active branch.');
        }
        DB::table('pos_price_lists')->whereNull('branch_id')->update(['branch_id' => $branchId]);

        Schema::table('pos_price_lists', function (Blueprint $table): void {
            $table->dropUnique('pos_price_lists_code_unique');
            $table->foreignId('branch_id')->nullable(false)->change();
            $table->unique(['branch_id', 'code'], 'pos_price_lists_branch_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pos_price_lists', function (Blueprint $table): void {
            $table->dropUnique('pos_price_lists_branch_code_unique');
            $table->unique('code');
            $table->dropIndex('pos_price_lists_branch_scope_idx');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
