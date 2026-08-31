<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->enum('accounting_profile', ['PAE', 'NPAE'])->nullable()->after('date_format');
            $table->enum('inventory_costing_method', ['AVG', 'FIFO'])->nullable()->after('accounting_profile');
            $table->boolean('allow_negative_stock')->nullable()->after('inventory_costing_method');
            $table->enum('negative_stock_cost_method', ['CURRENT_AVERAGE', 'LAST_KNOWN', 'STANDARD'])->nullable()->after('allow_negative_stock');
            $table->unsignedTinyInteger('fiscal_year_start_month')->nullable()->after('negative_stock_cost_method');
            $table->decimal('default_vat_rate', 5, 2)->nullable()->after('fiscal_year_start_month');
            $table->decimal('default_withholding_tax_rate', 5, 2)->nullable()->after('default_vat_rate');
            $table->enum('document_sequence_reset', ['NEVER', 'YEARLY', 'MONTHLY'])->nullable()->after('default_withholding_tax_rate');
            $table->unsignedInteger('posting_sla_minutes')->nullable()->after('document_sequence_reset');
            $table->unsignedInteger('recost_sla_minutes')->nullable()->after('posting_sla_minutes');
            $table->unsignedInteger('audit_retention_days')->nullable()->after('recost_sla_minutes');
            $table->unsignedInteger('file_retention_days')->nullable()->after('audit_retention_days');
            $table->date('effective_from')->nullable()->after('file_retention_days');
            $table->unsignedBigInteger('settings_version')->default(1)->after('effective_from');
        });

        Schema::create('company_setting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_setting_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('version');
            $table->date('effective_from');
            $table->json('values');
            $table->string('change_reason', 500);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['company_setting_id', 'version']);
        });

        $today = Carbon::today()->toDateString();

        DB::table('company_settings')->whereNull('effective_from')->update(['effective_from' => $today]);

        foreach (DB::table('company_settings')->get() as $setting) {
            DB::table('company_setting_versions')->insert([
                'company_setting_id' => $setting->id,
                'version' => $setting->settings_version,
                'effective_from' => $setting->effective_from,
                'values' => json_encode((array) $setting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'change_reason' => 'สร้าง snapshot เริ่มต้นจากข้อมูลเดิม',
                'changed_by' => $setting->updated_by,
                'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('company_setting_versions');

        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'accounting_profile',
                'inventory_costing_method',
                'allow_negative_stock',
                'negative_stock_cost_method',
                'fiscal_year_start_month',
                'default_vat_rate',
                'default_withholding_tax_rate',
                'document_sequence_reset',
                'posting_sla_minutes',
                'recost_sla_minutes',
                'audit_retention_days',
                'file_retention_days',
                'effective_from',
                'settings_version',
            ]);
        });
    }
};
