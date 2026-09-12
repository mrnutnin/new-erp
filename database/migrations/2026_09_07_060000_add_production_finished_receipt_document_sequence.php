<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('finance_document_sequences')) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM finance_document_sequences LIKE 'document_type'");
        if (! $column || ! str_starts_with(strtolower((string) $column->Type), 'enum(')) {
            return;
        }

        preg_match_all("/'([^']+)'/", (string) $column->Type, $matches);
        $types = array_values(array_unique(array_merge($matches[1] ?? [], ['PRODUCTION_FINISHED_RECEIPT'])));
        $enum = implode(',', array_map(fn (string $type): string => DB::getPdo()->quote($type), $types));
        DB::statement("ALTER TABLE finance_document_sequences MODIFY document_type ENUM({$enum}) NOT NULL");
    }

    public function down(): void
    {
        // Keep the enum value for existing customer documents on rollback.
    }
};
