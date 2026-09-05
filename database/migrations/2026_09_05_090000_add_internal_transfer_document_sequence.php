<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('finance_document_sequences')->updateOrInsert(
            ['warehouse_id' => null, 'document_type' => 'INTERNAL_TRANSFER'],
            ['name' => 'โอนเงินระหว่างบัญชี', 'prefix' => 'TRF', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('finance_document_sequences')->whereNull('warehouse_id')->where('document_type', 'INTERNAL_TRANSFER')->delete();
    }
};
