<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CostRevaluationInstallerContractTest extends TestCase
{
    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    public function test_phase_three_migration_and_installer_schema_guard_are_present(): void
    {
        $migration = file_get_contents($this->projectPath('database/migrations/2026_09_08_010000_create_wms_cost_revaluation_runs.php'));
        $applyMigration = file_get_contents($this->projectPath('database/migrations/2026_09_08_020000_add_apply_fields_to_wms_cost_revaluation_deltas.php'));
        $timelineIndexMigration = file_get_contents($this->projectPath('database/migrations/2026_09_08_030000_add_cost_timeline_partition_index.php'));
        $classificationMigration = file_get_contents($this->projectPath('database/migrations/2026_09_09_020000_classify_wms_cost_revaluation_deltas.php'));
        $installer = file_get_contents($this->projectPath('app/Modules/Installer/Services/DatabasePreparationService.php'));

        self::assertStringContainsString("Schema::create('wms_cost_revaluation_runs'", $migration);
        self::assertStringContainsString("Schema::create('wms_cost_revaluation_deltas'", $migration);
        self::assertStringContainsString('applied_cost_allocation_id', $applyMigration);
        self::assertStringContainsString('applied_at', $applyMigration);
        self::assertStringContainsString("'wms_cost_revaluation_runs'", $installer);
        self::assertStringContainsString("'wms_cost_revaluation_deltas'", $installer);
        self::assertStringContainsString("'applied_cost_allocation_id'", $installer);
        self::assertStringContainsString('wms_ca_timeline_partition_idx', $timelineIndexMigration);
        self::assertStringContainsString('wms_ca_timeline_partition_idx', $installer);
        foreach (['impact_bucket', 'target_event', 'target_warehouse_id', 'target_branch_id', 'stock_projection_delta_value'] as $column) {
            self::assertStringContainsString($column, $classificationMigration);
            self::assertStringContainsString($column, $installer);
        }
    }

    public function test_apply_gate_defaults_to_closed(): void
    {
        $config = file_get_contents($this->projectPath('config/erp.php'));

        self::assertStringContainsString("'revaluation_apply_enabled' => (bool) env('ERP_REVALUATION_APPLY_ENABLED', false)", $config);
        self::assertStringContainsString("'revaluation_gl_posting_enabled' => (bool) env('ERP_REVALUATION_GL_POSTING_ENABLED', false)", $config);
    }

    public function test_production_receipt_source_bridge_is_migrated_and_required_by_installer(): void
    {
        $migration = file_get_contents($this->projectPath('database/migrations/2026_09_09_010000_create_wms_production_receipt_sources.php'));
        $installer = file_get_contents($this->projectPath('app/Modules/Installer/Services/DatabasePreparationService.php'));

        self::assertStringContainsString("Schema::create('wms_production_receipt_sources'", $migration);
        self::assertStringContainsString("'source_allocation_id'", $migration);
        self::assertStringContainsString("'consumed_quantity'", $migration);
        self::assertStringContainsString("'consumed_value'", $migration);
        self::assertStringContainsString("'source_allocation_revision'", $migration);
        self::assertStringContainsString('wms_prs_alloc_receipt_idx', $migration);
        self::assertStringContainsString("'wms_production_receipt_sources'", $installer);
    }

    public function test_journal_uses_run_posting_date_and_open_period_gate(): void
    {
        $service = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationJournalPostingService.php'));
        $preflight = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationPreflightService.php'));

        self::assertStringContainsString("'entry_date' => \$postingDate", $service);
        self::assertStringContainsString("'document_date' => \$postingDate", $service);
        self::assertStringContainsString("where('status', 'OPEN')", $service);
        self::assertStringContainsString("'posting_period'", $preflight);
    }

    public function test_inventory_delta_uses_direction_consistently(): void
    {
        $apply = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationApplyService.php'));
        $journal = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationJournalPostingService.php'));
        $reconciliation = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationReconciliationService.php'));

        self::assertStringContainsString('$this->adjustProjection($delta, $source, $projectionDelta);', $apply);
        self::assertStringContainsString("'stock_projection_delta_value'", $apply);
        self::assertStringContainsString("BigDecimal::of((string) \$delta->delta_value)", $journal);
        self::assertStringContainsString('$journalInventory->minus($expectedJournalInventory)', $reconciliation);
        self::assertStringContainsString('toScale(2, RoundingMode::HALF_UP)', $reconciliation);
    }

    public function test_shadow_allocation_search_supports_inventory_adjustment_document_number(): void
    {
        $service = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostShadowCalculationService.php'));

        self::assertStringContainsString("where('source_type', 'INVENTORY')", $service);
        self::assertStringContainsString('adjustment_documents.document_number', $service);
        self::assertStringContainsString('adjustment_lines.stock_movement_id', $service);
        self::assertStringContainsString('adjustmentNumbers->get($allocation->stock_movement_id)', $service);
    }

    public function test_classified_revaluation_defaults_are_versioned_for_installer_updates(): void
    {
        $orchestrator = file_get_contents($this->projectPath('app/Modules/Installer/Services/SystemDefaultOrchestrator.php'));
        $coa = file_get_contents($this->projectPath('database/seeders/StandardChartOfAccountsSeeder.php'));

        self::assertStringContainsString("'accounting.chart_of_accounts' => '1.5'", $orchestrator);
        foreach (['ISSUE_EXPENSE', 'PURCHASE_RETURN_VARIANCE', '52700', '52800'] as $definition) {
            self::assertStringContainsString($definition, $coa);
        }
    }

    public function test_async_runtime_checkpoint_is_installed_and_bounded(): void
    {
        $migration = file_get_contents($this->projectPath('database/migrations/2026_09_09_030000_add_runtime_checkpoint_to_wms_cost_revaluation_runs.php'));
        $installer = file_get_contents($this->projectPath('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $apply = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationApplyService.php'));
        $job = file_get_contents($this->projectPath('app/Modules/Wms/Jobs/ApplyCostRevaluation.php'));
        $calculateJob = file_get_contents($this->projectPath('app/Modules/Wms/Jobs/CalculateCostRevaluation.php'));
        $partitionMigration = file_get_contents($this->projectPath('database/migrations/2026_09_09_040000_add_partition_counters_to_wms_cost_revaluation_runs.php'));

        foreach (['runtime_checkpoint', 'nodes_scanned', 'heartbeat_at'] as $column) {
            self::assertStringContainsString($column, $migration);
            self::assertStringContainsString($column, $installer);
        }
        self::assertStringContainsString("where('id', '>', \$afterId)", $apply);
        self::assertStringContainsString('limit($limit + 1)', $apply);
        self::assertStringContainsString("'WAITING_CONTINUATION'", $apply);
        self::assertStringContainsString("'cost-propagation'", $job);
        self::assertStringContainsString("'QUEUED'", $apply);
        self::assertStringContainsString("'LIMIT_REACHED'", $apply);
        self::assertStringContainsString('revaluation_calculation_node_budget', $apply);
        self::assertStringContainsString('CalculateCostRevaluation::dispatch', $apply);
        self::assertStringContainsString("'cost-propagation'", $calculateJob);
        $shadow = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostShadowCalculationService.php'));
        self::assertStringContainsString('calculatePartitionChunk', $shadow);
        self::assertStringContainsString("'resolver_state'", $shadow);
        self::assertStringContainsString("'MEMORY_LIMIT_REACHED'", $shadow);
        self::assertStringContainsString('revaluation_memory_budget_mb', $shadow);
        foreach (['expected_partitions', 'completed_partitions', 'failed_partitions'] as $column) {
            self::assertStringContainsString($column, $partitionMigration);
            self::assertStringContainsString($column, $installer);
        }
        self::assertStringContainsString("'pending_partitions'", $apply);
        self::assertStringContainsString('persistChunkRows', $apply);
        self::assertStringContainsString('bridgeFrontier', $apply);
        self::assertStringContainsString('revaluation_calculation_lease_seconds', $apply);
        self::assertStringContainsString("'STALE_ROOT_REVISION'", $apply);
        self::assertStringContainsString("data_get(\$runtime, 'calculation_lease.token')", $apply);
        self::assertStringContainsString("data_get(\$runtime, 'apply_lease.token')", $apply);
        self::assertStringContainsString("->limit(\$limit + 1)->pluck('id')", $apply);
        self::assertStringContainsString('$this->release(5)', file_get_contents($this->projectPath('app/Modules/Wms/Jobs/ApplyCostRevaluation.php')));
    }

    public function test_document_trigger_batch_is_installed_and_dispatched_after_commit(): void
    {
        $migration = file_get_contents($this->projectPath('database/migrations/2026_09_09_050000_create_wms_cost_revaluation_batches.php'));
        $installer = file_get_contents($this->projectPath('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $dispatcher = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostPropagationTriggerDispatcher.php'));
        $apply = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationApplyService.php'));

        foreach (['wms_cost_revaluation_batches', 'expected_root_lines', 'resolved_root_lines', 'trigger_snapshot', 'batch_id', 'partition_key'] as $contract) {
            self::assertStringContainsString($contract, $migration);
            self::assertStringContainsString($contract, $installer);
        }
        self::assertStringContainsString('trigger_identity', $dispatcher);
        self::assertStringContainsString('dispatchPartitionCalculation', $dispatcher);
        self::assertStringContainsString("'root_overrides'", $apply);
        self::assertStringContainsString('afterCommit()', $apply);
    }

    public function test_wms_posting_boundaries_are_wired_behind_a_closed_auto_trigger_gate(): void
    {
        $config = file_get_contents($this->projectPath('config/erp.php'));
        $dispatcher = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostPropagationTriggerDispatcher.php'));

        self::assertStringContainsString("ERP_REVALUATION_AUTO_TRIGGER_ENABLED', false", $config);
        self::assertStringContainsString('dispatchIfEnabled', $dispatcher);
        foreach ([
            'app/Modules/Wms/Services/OpeningBalanceService.php',
            'app/Modules/Wms/Services/IssueReturnService.php',
            'app/Modules/Wms/Services/TransferMovementService.php',
            'app/Modules/Wms/Services/ManualProductionReceiptPostingService.php',
            'app/Modules/Wms/Services/ProductionFinishedReceiptReversalService.php',
            'app/Modules/Wms/Services/InventoryAdjustmentDocumentReversalService.php',
            'app/Modules/Wms/Controllers/InventoryAdjustmentController.php',
        ] as $file) {
            self::assertStringContainsString('dispatchIfEnabled', file_get_contents($this->projectPath($file)), $file);
        }
    }

    public function test_purchase_sales_and_landed_cost_stock_writers_use_the_same_trigger_dispatcher(): void
    {
        foreach ([
            'app/Modules/Wms/Services/InventoryPurchaseProductionAdapter.php' => 'PURCHASE_DOCUMENT',
            'app/Modules/Wms/Services/InventoryPurchaseLiveReversalAdapter.php' => 'PURCHASE_DOCUMENT',
            'app/Modules/Wms/Services/CreditPurchaseInventoryReversalAdapter.php' => 'PURCHASE_CREDIT_RETURN',
            'app/Modules/Purchasing/Services/PurchaseReturnPostingService.php' => 'PURCHASE_RETURN',
            'app/Modules/Purchasing/Services/LandedCostPostingService.php' => 'LANDED_COST',
            'app/Modules/Pos/Services/PhysicalSalePostingService.php' => 'PHYSICAL_SALE',
            'app/Modules/Pos/Services/SalesReturnPostingService.php' => 'SALES_RETURN',
            'app/Modules/Pos/Services/PhysicalSaleCancellationService.php' => 'SALES_RETURN',
        ] as $file => $type) {
            $source = file_get_contents($this->projectPath($file));
            self::assertStringContainsString('CostPropagationTriggerDispatcher', $source, $file);
            self::assertStringContainsString("dispatchIfEnabled('{$type}'", $source, $file);
        }

        $salesCreditNote = file_get_contents($this->projectPath('app/Modules/Pos/Services/SalesDocumentPostingService.php'));
        self::assertStringNotContainsString('dispatchIfEnabled', $salesCreditNote, 'Financial-only sales credit note must not trigger stock revaluation.');
    }

    public function test_revaluation_recovery_reuses_existing_jobs_and_has_rbac_routes(): void
    {
        $service = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationRecoveryService.php'));
        $routes = file_get_contents($this->projectPath('app/Modules/Wms/Routes/web.php'));
        $rbac = file_get_contents($this->projectPath('database/seeders/RbacSeeder.php'));
        $installer = file_get_contents($this->projectPath('app/Modules/Installer/Services/SystemDefaultOrchestrator.php'));

        foreach (['CalculateCostRevaluation::dispatch', 'ApplyCostRevaluation::dispatch', "'CANCELLED'", "'APPLIED'", 'calculation_lease', 'apply_lease'] as $contract) {
            self::assertStringContainsString($contract, $service);
        }
        foreach (['wms.cost-revaluation.approve', 'wms.cost-revaluation.post', 'wms.cost-revaluation.recover', 'wms.cost-revaluation.cancel'] as $permission) {
            self::assertStringContainsString($permission, $routes);
            self::assertStringContainsString($permission, $rbac);
        }
        self::assertStringContainsString("'core.rbac' => '1.3'", $installer);
    }

    public function test_revaluation_show_defines_recovery_state_before_using_it(): void
    {
        $view = file_get_contents($this->projectPath('app/Modules/Wms/Views/stock-valuation/revaluation-show.blade.php'));

        self::assertStringNotContainsString('@php(', $view);
        self::assertLessThan(strpos($view, 'in_array($run->status, $resumeStatuses'), strpos($view, '$resumeStatuses ='));
        self::assertStringContainsString("['totals']['stock_projection_delta_value']", $view);
        self::assertStringNotContainsString("['totals']['allocation_value']", $view);
    }

    public function test_manual_document_trigger_reuses_planner_dispatcher_and_is_permissioned(): void
    {
        $manual = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostPropagationManualTriggerService.php'));
        $routes = file_get_contents($this->projectPath('app/Modules/Wms/Routes/web.php'));
        $rbac = file_get_contents($this->projectPath('database/seeders/RbacSeeder.php'));

        foreach (['CostPropagationTriggerPlanner', 'CostPropagationTriggerDispatcher', 'LandedCostPropagationCostResolver', '->limit(20)', 'manual_triggered'] as $contract) {
            self::assertStringContainsString($contract, $manual);
        }
        foreach (['manualTriggerOptions', 'manualTriggerPreview', 'manualTriggerDispatch', 'wms.cost-revaluation.trigger'] as $contract) {
            self::assertStringContainsString($contract, $routes);
        }
        self::assertStringContainsString('wms.cost-revaluation.trigger', $rbac);
        self::assertStringNotContainsString('CostShadowCalculationService', $manual, 'HTTP manual trigger must enqueue through the existing dispatcher, not calculate a graph synchronously.');
    }

    public function test_manual_scope_trigger_is_authorized_bounded_and_uses_existing_partition_jobs(): void
    {
        $scope = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostPropagationScopeTriggerService.php'));
        $job = file_get_contents($this->projectPath('app/Modules/Wms/Jobs/DispatchCostRevaluationScope.php'));
        $controller = file_get_contents($this->projectPath('app/Modules/Wms/Controllers/StockValuationController.php'));
        $view = file_get_contents($this->projectPath('app/Modules/Wms/Views/stock-valuation/manual-trigger.blade.php'));

        foreach (['actor->warehouses()', "where('business_date', '>=', \$scope['start_date'])", "where('id', '<='", 'limit($this->chunkSize() + 1)', 'dispatchPartitionCalculation', 'manual_scope_triggered'] as $contract) {
            self::assertStringContainsString($contract, $scope);
        }
        self::assertStringContainsString('ShouldBeUniqueUntilProcessing', $job);
        self::assertStringContainsString('dispatchChunk', $job);
        self::assertStringContainsString('manualTriggerScopePreview', $controller);
        foreach (['ทุกคลังในสาขา', 'เลือกหลายคลัง', 'ทุกสินค้า', 'เลือกหลายสินค้า'] as $label) {
            self::assertStringContainsString($label, $view);
        }
        self::assertStringContainsString('now()->startOfMonth()->toDateString()', $view);
        self::assertStringContainsString("'availability' =>", $scope);
    }

    public function test_landed_cost_dispatches_parent_receipt_cost_not_delta_unit_cost(): void
    {
        $posting = file_get_contents($this->projectPath('app/Modules/Purchasing/Services/LandedCostPostingService.php'));
        $resolver = file_get_contents($this->projectPath('app/Modules/Purchasing/Services/LandedCostPropagationCostResolver.php'));
        $planner = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostPropagationTriggerPlanner.php'));

        self::assertStringContainsString('propagationCosts->resolve', $posting);
        self::assertStringContainsString("where('allocation_type', 'RECOST')", $resolver);
        self::assertStringContainsString("where('idempotency_key', 'like', 'landed-cost:%')", $resolver);
        self::assertStringContainsString("where('business_date', '<=',", $resolver);
        self::assertStringContainsString('parent->unit_cost)->plus($deltaPerUnit)', $resolver);
        self::assertStringContainsString("'LANDED_COST' =>", $planner);
        self::assertStringContainsString("'force_effective_date'", $planner);
        self::assertStringContainsString("'allocation_lines'", $planner);
    }

    public function test_reversal_trigger_reuses_pipeline_with_compensating_effective_cost(): void
    {
        $resolver = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostRevaluationCompensationResolver.php'));
        $dispatcher = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostPropagationTriggerDispatcher.php'));
        $manual = file_get_contents($this->projectPath('app/Modules/Wms/Services/CostPropagationManualTriggerService.php'));

        foreach (['parent_allocation_id', "whereIn('status', ['APPLIED', 'GL_POSTED'])", 'summary.impact_date', 'source_run_ids', 'source_delta_ids'] as $contract) {
            self::assertStringContainsString($contract, $resolver);
        }
        self::assertStringContainsString('CostRevaluationCompensationResolver $compensations', $dispatcher);
        self::assertStringContainsString("'REVERSAL_AFTER_REVALUATION'", $dispatcher);
        self::assertStringContainsString("'compensation' => \$compensation", $manual);
    }
}
