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
            'company_settings' => ['logo_disk'],
            'purchase_requisitions' => ['deleted_at'],
            'purchase_orders' => ['tax_treatment', 'prices_include_vat', 'tax_decimal_places', 'tax_amount', 'deleted_at'],
            'goods_receipts' => ['deleted_at'],
            'purchase_documents' => ['deleted_at'],
            'purchasing_landed_costs' => ['deleted_at'],
            'purchase_order_lines' => ['tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'gross_amount'],
            'sales_intakes' => ['deleted_at'],
            'sales_quotations' => ['deleted_at'],
            'sales_orders' => ['required_delivery_date', 'production_legacy_eligible', 'deleted_at'],
            'sales_order_lines' => ['production_requested_at', 'production_requested_by', 'requested_delivery_date', 'requested_start_date', 'production_specification'],
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
            'users' => ['profile_image_disk', 'profile_image_path', 'signature_disk', 'signature_path', 'signature_checksum', 'signature_mime_type', 'position', 'deleted_at'],
            'roles' => ['deleted_at'],
            'parties' => ['normalized_name', 'deleted_at'],
            'wms_inventory_adjustments' => ['deleted_at'],
            'wms_transfers' => ['note', 'deleted_at'],
            'wms_inventory_adjustment_documents' => ['document_context', 'source_issue_id', 'deleted_at'],
            'wms_issue_documents' => ['reversed_by', 'reversed_at', 'reversal_reason', 'reversal_revision', 'deleted_at'],
            'wms_issue_lines' => ['reversal_movement_id', 'reversal_allocation_id', 'deleted_at'],
            'wms_opening_balance_batches' => ['deleted_at'],
            'wms_stock_count_documents' => ['deleted_at'],
            'wms_items' => ['cover_image_disk', 'cover_image_path', 'additional_images', 'can_manufacture', 'can_receive_production_scrap', 'deleted_at'],
            'wms_transfer_photos' => ['transfer_id', 'stage', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by'],
            'wms_document_photos' => ['document_type', 'document_id', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by'],
            'wms_production_receipt_sources' => ['receipt_document_id', 'issue_document_id', 'issue_line_id', 'source_allocation_id', 'source_allocation_revision', 'consumed_quantity', 'consumed_value'],
            'wms_stock_reservations' => ['quantity', 'consumed_quantity', 'status', 'idempotency_key'],
            'wms_stock_reservation_consumptions' => ['stock_reservation_id', 'stock_movement_id', 'quantity'],
            'production_boms' => ['branch_id', 'code', 'finished_item_id', 'base_uom_id', 'deleted_at'],
            'production_bom_revisions' => ['bom_id', 'revision_number', 'status', 'effective_from', 'activated_at'],
            'production_bom_lines' => ['bom_revision_id', 'line_number', 'component_item_id', 'uom_id', 'quantity'],
            'production_orders' => ['branch_id', 'issue_warehouse_id', 'receipt_warehouse_id', 'document_number', 'order_type', 'status', 'sales_order_id', 'sales_order_line_id', 'required_delivery_date', 'required_delivery_at', 'finished_item_id', 'uom_id', 'planned_quantity', 'completed_quantity', 'reject_quantity', 'bom_revision_id', 'planned_start_at', 'planned_finish_at', 'released_at', 'released_by', 'started_at', 'started_by', 'held_at', 'held_by', 'hold_reason', 'resumed_at', 'resumed_by', 'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by', 'deleted_at'],
            'production_order_issues' => ['production_order_id', 'branch_id', 'warehouse_id', 'reported_by', 'resolved_by', 'severity', 'description', 'resolution_method', 'status', 'reported_at', 'resolved_at'],
            'production_order_operations' => ['production_order_id', 'sequence', 'name', 'planned_minutes', 'status', 'started_at', 'completed_at', 'started_by', 'completed_by', 'notes'],
            'production_bom_line_substitutes' => ['bom_line_id', 'substitute_item_id', 'uom_id', 'quantity_factor', 'priority', 'notes'],
            'production_bom_operations' => ['bom_revision_id', 'sequence', 'name', 'planned_minutes', 'notes'],
            'production_order_materials' => ['production_order_id', 'line_number', 'source_bom_line_id', 'item_id', 'uom_id', 'required_quantity'],
            'production_order_scraps' => ['production_order_id', 'source_material_line_id', 'scrap_type', 'scrap_item_id', 'uom_id', 'quantity', 'recovery_unit_value', 'recovery_total_value', 'reason', 'status', 'reported_by', 'reported_at'],
            'production_order_events' => ['production_order_id', 'event_type', 'payload', 'occurred_at', 'created_by'],
            'crm_opportunities' => ['branch_id', 'party_id', 'owner_id', 'sales_intake_id', 'stage', 'next_action_at', 'deleted_at'],
            'crm_activities' => ['opportunity_id', 'type', 'due_at', 'completed_at', 'assigned_to'],
            'crm_product_interests' => ['opportunity_id', 'item_id', 'uom_id', 'quantity', 'target_unit_price', 'budget_amount', 'requirements'],
            'party_contacts' => ['party_id', 'name', 'decision_role', 'preferred_channel', 'contact_permission_status', 'lawful_basis', 'allow_phone', 'allow_email', 'allow_line', 'permission_recorded_at', 'is_primary', 'is_active', 'deleted_at'],
            'crm_sales_teams' => ['branch_id', 'name', 'manager_id', 'is_active', 'deleted_at'],
            'crm_sales_team_members' => ['team_id', 'user_id'],
            'notifications' => ['id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at'],
            'crm_notification_deliveries' => ['idempotency_key', 'user_id', 'kind', 'activity_id', 'branch_id'],
            'crm_notification_preferences' => ['user_id', 'reminder_enabled', 'reminder_minutes'],
            'crm_customer_duplicate_resolutions' => ['signature', 'match_type', 'match_key', 'resolved_by'],
            'crm_customer_assignments' => ['party_id', 'branch_id', 'owner_id', 'team_id', 'territory', 'backup_owner_id'],
            'push_subscriptions' => ['subscribable_type', 'subscribable_id', 'endpoint', 'public_key', 'auth_token', 'content_encoding'],
        ];
        $requiredTables = ['wms_cost_revaluation_batches', 'wms_cost_revaluation_runs', 'wms_cost_revaluation_deltas', 'wms_production_receipt_sources', 'wms_stock_reservation_consumptions', 'production_boms', 'production_bom_revisions', 'production_bom_lines', 'production_orders', 'production_order_materials', 'production_order_scraps', 'production_order_events', 'production_order_issues', 'production_order_operations', 'production_bom_line_substitutes', 'production_bom_operations', 'wms_transfer_photos', 'wms_document_photos', 'document_signature_snapshots', 'crm_opportunities', 'crm_activities', 'crm_product_interests', 'party_contacts', 'crm_sales_teams', 'crm_sales_team_members', 'notifications', 'crm_notification_deliveries', 'crm_notification_preferences', 'crm_customer_duplicate_resolutions', 'crm_customer_assignments', 'push_subscriptions'];

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
