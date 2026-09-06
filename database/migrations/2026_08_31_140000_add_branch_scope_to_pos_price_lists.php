<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_price_lists')) {
            return;
        }

        if (! Schema::hasColumn('pos_price_lists', 'branch_id')) {
            Schema::table('pos_price_lists', function (Blueprint $table): void {
                $table->foreignId('branch_id')->nullable()->after('currency')->constrained('branches')->restrictOnDelete();
            });
        }

        $indexes = collect(Schema::getIndexes('pos_price_lists'))->pluck('name')->all();
        if (! in_array('pos_price_lists_branch_scope_idx', $indexes, true)) {
            Schema::table('pos_price_lists', function (Blueprint $table): void {
                $table->index(['branch_id', 'is_active', 'effective_from', 'effective_to'], 'pos_price_lists_branch_scope_idx');
            });
        }

        $branchId = DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');
        if (! $branchId) {
            // Fresh installation creates the default branch after migrations.
            // Keep branch_id nullable until customer organization setup completes.
            return;
        }
        DB::table('pos_price_lists')->whereNull('branch_id')->update(['branch_id' => $branchId]);

        Schema::table('pos_price_lists', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('pos_price_lists'))->pluck('name')->all();
            if (in_array('pos_price_lists_code_unique', $indexes, true)) {
                $table->dropUnique('pos_price_lists_code_unique');
            }
            $table->foreignId('branch_id')->nullable(false)->change();
            if (! in_array('pos_price_lists_branch_code_unique', $indexes, true)) {
                $table->unique(['branch_id', 'code'], 'pos_price_lists_branch_code_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pos_price_lists')) {
            return;
        }

        Schema::table('pos_price_lists', function (Blueprint $table): void {
            $indexes = collect(Schema::getIndexes('pos_price_lists'))->pluck('name')->all();
            if (in_array('pos_price_lists_branch_code_unique', $indexes, true)) {
                $table->dropUnique('pos_price_lists_branch_code_unique');
            }
            if (! in_array('pos_price_lists_code_unique', $indexes, true)) {
                $table->unique('code');
            }
            if (in_array('pos_price_lists_branch_scope_idx', $indexes, true)) {
                $table->dropIndex('pos_price_lists_branch_scope_idx');
            }
            if (Schema::hasColumn('pos_price_lists', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });
    }
};
