<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templates = DB::table('finance_document_sequences')
            ->whereNull('warehouse_id')
            ->pluck('id', 'document_type');

        $legacyCounters = DB::table('finance_document_sequences as sequences')
            ->join('warehouses', 'warehouses.id', '=', 'sequences.warehouse_id')
            ->whereNotNull('sequences.warehouse_id')
            ->select([
                'sequences.document_type',
                'warehouses.branch_id',
                DB::raw('MAX(sequences.next_number) as next_number'),
                DB::raw('MAX(sequences.last_reset_key) as last_reset_key'),
            ])
            ->groupBy('sequences.document_type', 'warehouses.branch_id')
            ->get();

        foreach ($legacyCounters as $legacyCounter) {
            $sequenceId = $templates[$legacyCounter->document_type] ?? null;
            if ($sequenceId === null) {
                continue;
            }

            $existing = DB::table('finance_document_sequence_counters')
                ->where('document_sequence_id', $sequenceId)
                ->where('branch_id', $legacyCounter->branch_id)
                ->first();

            $values = [
                'next_number' => max((int) ($existing->next_number ?? 1), (int) $legacyCounter->next_number),
                'last_reset_key' => max((string) ($existing->last_reset_key ?? ''), (string) ($legacyCounter->last_reset_key ?? '')) ?: null,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('finance_document_sequence_counters')->where('id', $existing->id)->update($values);

                continue;
            }

            DB::table('finance_document_sequence_counters')->insert($values + [
                'document_sequence_id' => $sequenceId,
                'branch_id' => $legacyCounter->branch_id,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Existing counters may have been issued after this migration, so do not delete them.
    }
};
