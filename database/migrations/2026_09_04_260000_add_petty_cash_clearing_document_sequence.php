<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('finance_petty_cash_clearings', 'document_number')) {
            Schema::table('finance_petty_cash_clearings', fn (Blueprint $table) => $table->string('document_number', 40)->nullable()->after('id'));
        }

        $userId = DB::table('users')->orderBy('id')->value('id');
        foreach (DB::table('finance_petty_cash_clearings')->whereNull('document_number')->orderBy('warehouse_id')->orderBy('id')->get() as $clearing) {
            $number = DB::table('finance_petty_cash_clearings')->where('warehouse_id', $clearing->warehouse_id)->whereNotNull('document_number')->count() + 1;
            DB::table('finance_petty_cash_clearings')->where('id', $clearing->id)->update(['document_number' => sprintf('PCC-%s-%06d', substr((string) $clearing->clearing_date, 0, 4), $number)]);
        }

        Schema::table('finance_petty_cash_clearings', fn (Blueprint $table) => $table->unique('document_number', 'finance_petty_cash_clearings_document_number_unique'));
        foreach (DB::table('warehouses')->whereNull('deleted_at')->pluck('id') as $warehouseId) {
            DB::table('finance_document_sequences')->updateOrInsert(
                ['warehouse_id' => $warehouseId, 'document_type' => 'PETTY_CASH_CLEARING'],
                ['name' => 'ใบเคลียร์เงินสดย่อย', 'prefix' => 'PCC', 'number_format' => '{PREFIX}-{YYYY}-{NUMBER:6}', 'reset_rule' => 'YEARLY', 'next_number' => 1, 'is_active' => true, 'number_reuse_policy' => 'NEVER_REUSE', 'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('finance_document_sequences')->where('document_type', 'PETTY_CASH_CLEARING')->delete();
        Schema::table('finance_petty_cash_clearings', function (Blueprint $table): void {
            $table->dropUnique('finance_petty_cash_clearings_document_number_unique');
            $table->dropColumn('document_number');
        });
    }
};
