<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $settlementColumns = [
            'reversal_journal_entry_id' => fn (Blueprint $table) => $table->foreignId('reversal_journal_entry_id')->nullable()->after('journal_entry_id')->constrained('journal_entries')->restrictOnDelete(),
            'reversed_by' => fn (Blueprint $table) => $table->foreignId('reversed_by')->nullable()->after('posted_by')->constrained('users')->nullOnDelete(),
            'reversed_at' => fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable()->after('reversed_by'),
            'reversal_date' => fn (Blueprint $table) => $table->date('reversal_date')->nullable()->after('reversed_at'),
            'reversal_reason' => fn (Blueprint $table) => $table->string('reversal_reason', 500)->nullable()->after('reversal_date'),
        ];
        foreach ($settlementColumns as $column => $definition) {
            if (! Schema::hasColumn('finance_settlements', $column)) {
                Schema::table('finance_settlements', $definition);
            }
        }
        foreach (['finance_allocations', 'finance_tax_realizations', 'finance_withholding_realizations'] as $tableName) {
            foreach (['reversed_by', 'reversed_at', 'reversal_date', 'reversal_reason'] as $column) {
                if (Schema::hasColumn($tableName, $column)) {
                    continue;
                }
                Schema::table($tableName, function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'reversed_by' => $table->foreignId($column)->nullable()->after('created_by')->constrained('users')->nullOnDelete(),
                        'reversed_at' => $table->timestamp($column)->nullable()->after('reversed_by'),
                        'reversal_date' => $table->date($column)->nullable()->after('reversed_at'),
                        default => $table->string($column, 500)->nullable()->after('reversal_date'),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['finance_allocations', 'finance_tax_realizations', 'finance_withholding_realizations'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['reversed_by']);
                $table->dropColumn(['reversed_by', 'reversed_at', 'reversal_date', 'reversal_reason']);
            });
        }
        Schema::table('finance_settlements', function (Blueprint $table): void {
            $table->dropForeign(['reversal_journal_entry_id']);
            $table->dropForeign(['reversed_by']);
            $table->dropColumn(['reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_date', 'reversal_reason']);
        });
    }
};
