<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { if (! Schema::hasColumn('asset_categories', 'disposal_clearing_account_id')) Schema::table('asset_categories', fn (Blueprint $table) => $table->foreignId('disposal_clearing_account_id')->nullable()->after('disposal_loss_account_id')->constrained('accounts')->nullOnDelete()); }
    public function down(): void { if (Schema::hasColumn('asset_categories', 'disposal_clearing_account_id')) Schema::table('asset_categories', fn (Blueprint $table) => $table->dropConstrainedForeignId('disposal_clearing_account_id')); }
};
