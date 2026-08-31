<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE accounts MODIFY control_account_type ENUM('AR','AP','INVENTORY','CASH','BANK','CREDIT_CARD','CHEQUE','FIXED_ASSET','INPUT_VAT','OUTPUT_VAT','WITHHOLDING_TAX','WIP') NULL");
        DB::statement("ALTER TABLE finance_bank_accounts MODIFY type ENUM('CASH','BANK','CREDIT_CARD','CHEQUE') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE finance_bank_accounts MODIFY type ENUM('CASH','BANK') NOT NULL");
        DB::statement("ALTER TABLE accounts MODIFY control_account_type ENUM('AR','AP','INVENTORY','CASH','BANK','FIXED_ASSET','INPUT_VAT','OUTPUT_VAT','WITHHOLDING_TAX','WIP') NULL");
    }
};
