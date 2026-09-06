<?php

namespace App\Modules\Installer\Services;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Installer\Models\InstallationSession;
use App\Modules\Platform\Models\MigrationImportBatch;
use App\Modules\Accounting\Models\Account;
use Database\Seeders\SystemDocumentSequenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallationValidationService
{
    /** @return array{can_go_live:bool, checks:array<int, array<string, mixed>>, required_passed:int, required_total:int} */
    public function run(): array
    {
        $company = CompanySetting::query()->first();
        $branch = Branch::query()->where('code', '00000')->first();
        $warehouse = Warehouse::query()->where('code', 'WH001')->first();
        $admin = User::query()->whereHas('roles', fn ($query) => $query->where('roles.code', 'admin'))->exists();
        $accountingEnabled = Program::query()->where('code', 'accounting')->where('is_enabled', true)->exists();

        $checks = [
            $this->check('company_information', 'Company Information', 'REQUIRED', (bool) ($company?->company_name), 'กรอกข้อมูลบริษัท', 'installer.index'),
            $this->check('administrator', 'Administrator', 'REQUIRED', $admin, 'สร้าง Administrator', 'installer.index'),
            $this->check('main_branch', 'สำนักงานใหญ่', 'REQUIRED', (bool) ($branch?->is_active), 'สร้างสำนักงานใหญ่ 00000', 'installer.index'),
            $this->check('main_warehouse', 'คลังหลัก', 'REQUIRED', (bool) ($warehouse?->is_active), 'สร้างคลังหลัก WH001', 'installer.index'),
            $this->check('document_numbering', 'Document Numbering', 'RECOMMENDED', $this->hasRequiredDocumentSequences(), 'ตรวจสอบเลขที่เอกสาร', 'settings.document-sequences.index'),
            $this->check('accounting_setup', 'Accounting Defaults', $accountingEnabled ? 'REQUIRED' : 'OPTIONAL', ! $accountingEnabled || $this->hasStandardAccountingSetup(), 'ตรวจสอบผังบัญชี', 'accounting.accounts.index'),
            $this->check('inventory_setup', 'Inventory Defaults', 'RECOMMENDED', (bool) ($company?->inventory_costing_method), 'ตรวจสอบนโยบายคลังสินค้า', 'wms.index'),
            $this->check('opening_stock', 'Opening Stock', 'RECOMMENDED', ! Program::query()->where('code', 'wms')->where('is_enabled', true)->exists() || MigrationImportBatch::query()->where('type', 'WMS_OPENING_BALANCE')->where('status', 'COMMITTED')->exists(), 'นำเข้าหรือสร้างยอดยกมาสินค้า', 'wms.opening-balances.create'),
            $this->check('approval_workflow', 'Approval Workflow', 'OPTIONAL', true, 'ตั้งค่า Workflow ภายหลังได้', 'settings.workflow.index'),
        ];

        $required = collect($checks)->where('type', 'REQUIRED');
        $passed = $required->where('status', 'pass')->count();
        $result = [
            'can_go_live' => $passed === $required->count(),
            'checks' => $checks,
            'required_passed' => $passed,
            'required_total' => $required->count(),
        ];

        $session = InstallationSession::query()->latest('id')->first();
        if ($session) {
            DB::transaction(function () use ($session, $checks, $result): void {
                foreach ($checks as $check) {
                    $session->checklists()->updateOrCreate(['checklist_code' => $check['code']], [
                        'step_code' => 'validation',
                        'type' => $check['type'],
                        'status' => strtoupper($check['status']),
                        'completed_at' => $check['status'] === 'pass' ? now() : null,
                    ]);
                }
                $session->steps()->updateOrCreate(['step_code' => 'validation'], [
                    'status' => $result['can_go_live'] ? 'COMPLETED' : 'IN_PROGRESS',
                    'completed_at' => $result['can_go_live'] ? now() : null,
                    'metadata' => ['required_passed' => $result['required_passed'], 'required_total' => $result['required_total']],
                ]);
            });
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function check(string $code, string $label, string $type, bool $passed, string $fix, string $route): array
    {
        return ['code' => $code, 'label' => $label, 'type' => $type, 'status' => $passed ? 'pass' : ($type === 'OPTIONAL' ? 'warning' : 'fail'), 'fix' => $fix, 'route' => $route];
    }

    private function hasAnyTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    private function hasRows(string $table): bool
    {
        return Schema::hasTable($table) && DB::table($table)->exists();
    }

    private function hasStandardAccountingSetup(): bool
    {
        if (! Schema::hasTable('accounts') || ! Schema::hasTable('accounting_account_mappings')) {
            return false;
        }

        $requiredCodes = ['12000', '13000', '21000', '41000', '52000'];

        return Account::query()
            ->whereIn('code', $requiredCodes)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->count() === count($requiredCodes)
            && DB::table('accounting_account_mappings')
                ->whereNull('event_code')
                ->whereIn('key', ['SALES_AR', 'INVENTORY_DEFAULT', 'COGS_DEFAULT'])
                ->where('is_active', true)
                ->count() === 3;
    }

    private function hasRequiredDocumentSequences(): bool
    {
        if (! Schema::hasTable('finance_document_sequences')) {
            return false;
        }

        $required = collect(SystemDocumentSequenceSeeder::requiredDefinitions())->pluck('type');

        return DB::table('finance_document_sequences')
            ->whereNull('warehouse_id')
            ->where('is_active', true)
            ->whereIn('document_type', $required)
            ->select('document_type')
            ->distinct()
            ->count() === $required->unique()->count();
    }
}
