<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_open_items')
            ->whereNull('tax_point_date')
            ->update(['tax_point_date' => new Expression('document_date')]);
    }

    public function down(): void
    {
        // Deliberately irreversible: the original NULL-vs-document-date state
        // cannot be reconstructed safely after this historical backfill.
    }
};
