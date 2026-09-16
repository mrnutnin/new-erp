<?php

namespace App\Modules\Installer\Services;

use App\Modules\Installer\Models\InstallationSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DatabasePreparationService
{
    public function __construct(private readonly InstallerStateStore $stateStore) {}

    /** @return array{status:string, message:string, output:string, error:?string} */
    public function prepare(): array
    {
        $this->stateStore->write([
            'status' => 'IN_PROGRESS',
            'step_code' => 'database',
            'message' => 'กำลังเตรียมฐานข้อมูล',
        ]);

        try {
            $exitCode = Artisan::call('migrate', ['--force' => true]);
            $output = trim(Artisan::output());

            if ($exitCode === 0) {
                $installerExitCode = Artisan::call('migrate', [
                    '--path' => 'database/migrations/installer',
                    '--force' => true,
                ]);
                $installerOutput = trim(Artisan::output());
                $output = trim($output."\n".$installerOutput);
                $exitCode = $installerExitCode;
            }

            if ($exitCode !== 0 || ! Schema::hasTable('installation_sessions')) {
                throw new \RuntimeException($output !== '' ? $output : 'Migration did not complete successfully.');
            }

            $this->assertRequiredSchemaReady();

            $session = DB::transaction(function () use ($output): InstallationSession {
                $session = InstallationSession::query()->latest('id')->first();

                if (! $session) {
                    $session = InstallationSession::query()->create([
                        'status' => 'DATABASE_READY',
                        'progress' => 10,
                        'started_at' => now(),
                        'metadata' => ['created_by' => 'web_installer'],
                    ]);
                } else {
                    $session->forceFill([
                        'status' => 'DATABASE_READY',
                        'progress' => max(10, (int) $session->progress),
                    ])->save();
                }

                $session->steps()->updateOrCreate(['step_code' => 'database'], [
                    'status' => 'COMPLETED',
                    'started_at' => now(),
                    'completed_at' => now(),
                    'error_message' => null,
                    'metadata' => ['migration_output' => str($output ?? '')->limit(2000)->toString()],
                ]);

                return $session;
            });

            $this->stateStore->write([
                'status' => 'COMPLETED',
                'step_code' => 'database',
                'message' => 'เตรียมฐานข้อมูลสำเร็จ',
                'installation_session_id' => $session->id,
            ]);

            return ['status' => 'success', 'message' => 'เตรียมฐานข้อมูลสำเร็จแล้ว', 'output' => $output ?? '', 'error' => null];
        } catch (Throwable $exception) {
            report($exception);
            $message = 'ไม่สามารถเตรียมฐานข้อมูลได้ กรุณาตรวจสอบการเชื่อมต่อและลองใหม่';

            $this->stateStore->write([
                'status' => 'FAILED',
                'step_code' => 'database',
                'message' => $message,
                'technical_detail' => $exception->getMessage(),
            ]);

            if ($this->hasInstallerTables()) {
                DB::table('installation_logs')->insert([
                    'step_code' => 'database',
                    'action' => 'prepare_database',
                    'status' => 'FAILED',
                    'technical_detail' => $exception->getMessage(),
                    'ip_address' => request()->ip(),
                    'created_at' => now(),
                ]);
            }

            return ['status' => 'failed', 'message' => $message, 'output' => '', 'error' => $this->safeError($exception)];
        }
    }

    private function safeError(Throwable $exception): string
    {
        $message = trim((string) $exception->getMessage());
        $message = preg_replace('/(password|passwd|pwd)\s*[=:]\s*[^\s,;]+/i', '$1=[REDACTED]', $message) ?: $message;

        $message = $message !== '' ? $message : 'ไม่พบรายละเอียดจากระบบฐานข้อมูล';

        return str(sprintf('[%s] %s (at %s:%d)', $exception::class, $message, basename($exception->getFile()), $exception->getLine()))->limit(4000)->toString();
    }

    private function hasInstallerTables(): bool
    {
        try {
            return Schema::hasTable('installation_sessions') && Schema::hasTable('installation_logs');
        } catch (Throwable) {
            return false;
        }
    }

    private function assertRequiredSchemaReady(): void
    {
        $requiredColumns = [
            'purchase_requisitions' => ['deleted_at'],
            'purchase_orders' => ['tax_treatment', 'prices_include_vat', 'tax_decimal_places', 'tax_amount', 'deleted_at'],
            'goods_receipts' => ['deleted_at'],
            'purchase_documents' => ['deleted_at'],
            'purchasing_landed_costs' => ['deleted_at'],
            'purchase_order_lines' => ['tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'gross_amount'],
            'sales_intakes' => ['deleted_at'],
            'sales_quotations' => ['deleted_at'],
            'sales_orders' => ['deleted_at'],
            'pos_physical_sales' => ['deleted_at'],
            'sales_documents' => ['deleted_at'],
            'pos_sales_returns' => ['deleted_at'],
            'finance_bank_accounts' => ['deleted_at'],
            'finance_employee_advances' => ['deleted_at'],
            'finance_employee_advance_clearings' => ['deleted_at'],
            'finance_internal_transfers' => ['deleted_at'],
            'finance_other_categories' => ['deleted_at'],
            'finance_payment_terms' => ['deleted_at'],
            'finance_payment_vouchers' => ['deleted_at'],
            'finance_petty_cash_clearings' => ['deleted_at'],
            'finance_petty_cash_vouchers' => ['deleted_at'],
            'finance_petty_cash_funds' => ['deleted_at'],
            'finance_petty_cash_top_ups' => ['deleted_at'],
            'finance_settlements' => ['deleted_at'],
            'accounts' => ['deleted_at'],
            'tax_codes' => ['deleted_at'],
            'journal_entries' => ['deleted_at'],
            'accounting_bank_statements' => ['deleted_at'],
            'assets' => ['deleted_at'],
            'asset_categories' => ['deleted_at'],
            'asset_locations' => ['deleted_at'],
            'asset_capitalizations' => ['deleted_at'],
            'asset_transfers' => ['deleted_at'],
            'asset_counts' => ['deleted_at'],
            'asset_depreciation_runs' => ['deleted_at'],
            'asset_depreciation_policy_changes' => ['deleted_at'],
            'asset_impairments' => ['deleted_at'],
            'asset_disposals' => ['deleted_at'],
            'asset_maintenance_requests' => ['deleted_at'],
            'asset_maintenance_schedules' => ['deleted_at'],
            'branches' => ['deleted_at'],
            'warehouses' => ['deleted_at'],
            'users' => ['deleted_at'],
            'roles' => ['deleted_at'],
            'wms_inventory_adjustments' => ['deleted_at'],
            'wms_inventory_adjustment_documents' => ['document_context', 'deleted_at'],
            'wms_opening_balance_batches' => ['deleted_at'],
            'wms_stock_count_documents' => ['deleted_at'],
            'wms_production_receipt_sources' => ['receipt_document_id', 'issue_document_id', 'issue_line_id', 'source_allocation_id', 'source_allocation_revision', 'consumed_quantity', 'consumed_value'],
        ];
        $requiredTables = ['wms_cost_revaluation_batches', 'wms_cost_revaluation_runs', 'wms_cost_revaluation_deltas', 'wms_production_receipt_sources'];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException("Required table is missing after Prepare Database: {$table}");
            }
        }
        foreach (['applied_cost_allocation_id', 'applied_at', 'impact_bucket', 'target_event', 'target_warehouse_id', 'target_branch_id', 'stock_projection_delta_value'] as $column) {
            if (! Schema::hasColumn('wms_cost_revaluation_deltas', $column)) {
                throw new \RuntimeException("Required column is missing after Prepare Database: wms_cost_revaluation_deltas.{$column}");
            }
        }
        foreach (['runtime_checkpoint', 'nodes_scanned', 'heartbeat_at', 'expected_partitions', 'completed_partitions', 'failed_partitions'] as $column) {
            if (! Schema::hasColumn('wms_cost_revaluation_runs', $column)) {
                throw new \RuntimeException("Required column is missing after Prepare Database: wms_cost_revaluation_runs.{$column}");
            }
        }
        foreach (['batch_id', 'partition_key'] as $column) {
            if (! Schema::hasColumn('wms_cost_revaluation_runs', $column)) {
                throw new \RuntimeException("Required column is missing after Prepare Database: wms_cost_revaluation_runs.{$column}");
            }
        }
        foreach (['source_document_type', 'source_document_id', 'document_date', 'source_revision', 'expected_root_lines', 'resolved_root_lines', 'expected_partitions', 'completed_partitions', 'failed_partitions', 'trigger_snapshot'] as $column) {
            if (! Schema::hasColumn('wms_cost_revaluation_batches', $column)) {
                throw new \RuntimeException("Required column is missing after Prepare Database: wms_cost_revaluation_batches.{$column}");
            }
        }
        if (! collect(Schema::getIndexes('wms_cost_allocations'))->contains('name', 'wms_ca_timeline_partition_idx')) {
            throw new \RuntimeException('Required index is missing after Prepare Database: wms_ca_timeline_partition_idx');
        }

        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new \RuntimeException("Required table is missing after Prepare Database: {$table}");
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new \RuntimeException("Required column is missing after Prepare Database: {$table}.{$column}");
                }
            }
        }
    }
}
