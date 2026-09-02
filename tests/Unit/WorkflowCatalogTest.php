<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WorkflowCatalog;
use App\Modules\Settings\Services\GlobalSettings;
use Mockery;
use PHPUnit\Framework\TestCase;

class WorkflowCatalogTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unknown_program_does_not_fallback_to_wms(): void
    {
        $this->assertSame([], WorkflowCatalog::for('not-a-program'));
    }

    public function test_core_catalogs_expose_setup_and_daily_mode_metadata(): void
    {
        foreach (['settings', 'wms', 'finance', 'accounting'] as $program) {
            $catalog = WorkflowCatalog::for($program);

            $this->assertNotEmpty($catalog);
            $this->assertContains($catalog[0]['mode'], ['setup', 'daily']);
            $this->assertSame($catalog[0]['mode'], $catalog[0]['steps'][0]['mode']);
            $this->assertContains($catalog[0]['mode_label'], ['เริ่มใช้งานครั้งแรก', 'งานประจำวัน']);
            $this->assertNotEmpty($catalog[0]['steps'][0]['next_action']);
            $this->assertNotEmpty($catalog[0]['steps'][0]['recovery_hint']);
            $this->assertStringContainsString('Approved/Posted ห้ามลบ', $catalog[0]['steps'][0]['recovery_hint']);
        }

        $wms = WorkflowCatalog::for('wms');
        $this->assertSame(['setup', 'daily', 'setup', 'daily'], array_column($wms, 'mode'));
        $inventorySetup = $wms[2];
        $inventoryDaily = $wms[3];
        $this->assertSame('นโยบายต้นทุน AVG/FIFO', $inventorySetup['steps'][1]['label']);
        $preflight = collect($inventoryDaily['steps'])->firstWhere('label', 'Inventory→GL Preflight');
        $valuation = collect($inventoryDaily['steps'])->firstWhere('label', 'Valuation / RECOST');
        $reconciliation = collect($inventoryDaily['steps'])->firstWhere('label', 'GL Reconciliation / Resolve');
        $this->assertNotNull($preflight);
        $this->assertNotNull($reconciliation);
        $this->assertSame('wms.stock-valuation.index', $valuation['route']);
        $this->assertStringContainsString('Historical valuation', $valuation['limitation_note']);
        $this->assertStringContainsString('current projection', $valuation['effect']);
        $decisionCards = collect($inventorySetup['decision_cards']);
        $this->assertCount(7, $decisionCards);
        $this->assertSame(['setup', 'setup', 'daily', 'daily', 'setup', 'daily', 'daily'], $decisionCards->pluck('mode')->all());
        $this->assertNull($decisionCards->firstWhere('code', 'inventory-gl-reconciliation')['route']);
        $this->assertSame([
            'inventory_purchase_event_wiring',
            'atomic_journal_movement_allocation_linkage',
            'reconciliation_zero_gate',
        ], $decisionCards->whereIn('code', [
            'inventory_purchase_event_wiring',
            'atomic_journal_movement_allocation_linkage',
            'reconciliation_zero_gate',
        ])->pluck('code')->all());
    }

    public function test_core_workflow_centers_keep_setup_and_daily_tasks_separate_with_recovery_guidance(): void
    {
        foreach (['wms', 'pos', 'finance', 'accounting'] as $program) {
            $workflows = collect(WorkflowCatalog::for($program));

            $this->assertSame(['daily', 'setup'], $workflows->pluck('mode')->unique()->sort()->values()->all(), $program);

            foreach ($workflows->flatMap(fn (array $workflow) => $workflow['steps']) as $step) {
                $this->assertContains($step['mode'], ['setup', 'daily'], $program);
                $this->assertNotSame('', trim((string) ($step['recovery_hint'] ?? '')), $program.': '.$step['label']);
            }
        }
    }

    public function test_pos_catalog_separates_setup_and_daily_sales_flow(): void
    {
        $catalog = WorkflowCatalog::for('pos');

        $this->assertSame(['setup', 'setup', 'daily', 'daily', 'daily', 'daily'], array_column($catalog, 'mode'));
        $this->assertSame('pos.customer-groups.index', $catalog[1]['steps'][0]['route']);
        $this->assertSame('pos.sales-intakes.index', $catalog[2]['steps'][0]['route']);
        $this->assertNotEmpty($catalog[2]['steps'][0]['recovery_hint']);
    }

    public function test_pos_catalog_exposes_all_operational_workstreams_with_real_routes(): void
    {
        $catalog = collect(WorkflowCatalog::for('pos'));

        $this->assertSame([
            'sales-posting-readiness',
            'sales-setup',
            'sales-documents',
            'sales-aftercare',
            'sales-commission',
            'sales-performance',
        ], $catalog->pluck('code')->all());
        $this->assertSame('pos.physical-sales.index', $catalog->firstWhere('code', 'sales-documents')['steps'][3]['route']);
        $this->assertSame('pos.sales-returns.index', $catalog->firstWhere('code', 'sales-aftercare')['steps'][2]['route']);
        $this->assertSame('pos.sales-commission-payment-batches.create', $catalog->firstWhere('code', 'sales-commission')['steps'][1]['route']);
        $this->assertSame('pos.sales-reports.campaign-roi.index', $catalog->firstWhere('code', 'sales-performance')['steps'][3]['route']);
    }

    public function test_finance_daily_flow_routes_advance_and_blocks_unimplemented_cash_paths_safely(): void
    {
        $steps = collect(WorkflowCatalog::for('finance'))
            ->firstWhere('code', 'record-to-cash')['steps'];

        $advance = collect($steps)->firstWhere('label', 'เงินล่วงหน้า / เงินมัดจำ');
        $this->assertSame('finance.advance-deposits.index', $advance['route']);
        $this->assertSame('finance.advance-deposits.view', $advance['permission']);
        $this->assertNotSame('', trim((string) $advance['recovery_hint']));

        foreach (['Petty Cash', 'เงินทดรองพนักงาน'] as $label) {
            $step = collect($steps)->firstWhere('label', $label);

            $this->assertNotNull($step, $label);
            $this->assertNull($step['route'], $label);
            $this->assertNotSame('', trim((string) $step['block_reason']), $label);
            $this->assertNotSame('', trim((string) $step['recovery_hint']), $label);
            $this->assertStringContainsString('รอ', $step['recovery_hint'], $label);
        }
    }

    public function test_finance_approval_guidance_allows_small_team_policy(): void
    {
        $steps = collect(WorkflowCatalog::for('finance'))
            ->flatMap(fn (array $workflow) => $workflow['steps']);

        $voucher = $steps->firstWhere('label', 'ใบขอจ่าย / ใบสำคัญจ่าย');
        $this->assertStringContainsString('approval policy', $voucher['effect']);
        $this->assertStringContainsString('ทีมเล็ก', $voucher['effect']);
        $this->assertStringContainsString('Draft', $voucher['recovery_hint']);
        $this->assertStringContainsString('reversal', $voucher['recovery_hint']);
    }

    public function test_small_team_workflows_do_not_imply_a_second_approver_is_always_required(): void
    {
        $catalogs = collect(['finance', 'accounting', 'wms', 'pos'])
            ->flatMap(fn (string $program): array => WorkflowCatalog::for($program));

        $approvalSteps = $catalogs
            ->flatMap(fn (array $workflow) => $workflow['steps'])
            ->filter(fn (array $step): bool => str_contains($step['effect'], 'อนุมัติ'));

        foreach ($approvalSteps as $step) {
            $this->assertStringContainsString('approval policy', $step['effect']);
            $this->assertStringContainsString('ทีมเล็ก', $step['effect']);
        }
    }

    public function test_wms_master_recovery_guidance_preserves_history_with_soft_delete(): void
    {
        $steps = collect(WorkflowCatalog::for('wms'))
            ->flatMap(fn (array $workflow) => $workflow['steps']);

        $this->assertStringContainsString('Soft Delete', $steps->firstWhere('label', 'Item / Category / UOM')['recovery_hint']);
        $this->assertStringContainsString('ห้าม hard delete', $steps->firstWhere('label', 'Supplier')['recovery_hint']);
    }

    public function test_wms_stock_flow_explains_posted_recovery_without_mutating_the_ledger(): void
    {
        $steps = collect(WorkflowCatalog::for('wms'))
            ->flatMap(fn (array $workflow) => $workflow['steps']);

        $stockFlow = $steps->firstWhere('label', 'Receipt / Issue / Transfer');
        $this->assertSame('daily', $stockFlow['mode']);
        $this->assertSame('wms.stock.index', $stockFlow['route']);
        $this->assertStringContainsString('reversal', $stockFlow['recovery_hint']);
        $this->assertStringContainsString('ห้ามลบ', $stockFlow['recovery_hint']);
    }

    public function test_wms_purchase_flow_exposes_receipt_and_credit_purchase_with_safe_boundary_guidance(): void
    {
        $steps = collect(WorkflowCatalog::for('wms'))
            ->firstWhere('code', 'procure-to-pay')['steps'];
        $receipt = collect($steps)->firstWhere('label', 'Goods Receipt / ตรวจรับสินค้า');
        $creditPurchase = collect($steps)->firstWhere('label', 'Credit Purchase / ใบซื้อเชื่อ');

        $this->assertSame('purchasing.purchase-receipts.index', $receipt['route']);
        $this->assertSame('purchasing.purchase-receipts.view', $receipt['permission']);
        $this->assertStringContainsString('ไม่สร้าง GL ซ้ำ', $receipt['effect']);
        $this->assertStringContainsString('ห้ามรับเกิน PO', $receipt['recovery_hint']);
        $this->assertSame('purchasing.purchase-documents.index', $creditPurchase['route']);
        $this->assertStringContainsString('3-way match', $creditPurchase['effect']);
        $this->assertStringContainsString('variance', $creditPurchase['recovery_hint']);
    }

    public function test_wms_purchase_flow_explains_service_expense_exception(): void
    {
        $workflow = collect(WorkflowCatalog::for('wms'))->firstWhere('code', 'procure-to-pay');
        $decision = collect($workflow['decision_cards'])->firstWhere('code', 'service-expense-purchase');

        $this->assertSame('purchasing.purchase-documents.index', $decision['route']);
        $this->assertStringContainsString('ไม่ต้องผ่าน Goods Receipt', $decision['description']);
        $this->assertStringContainsString('GL', $decision['block_reason']);
        $this->assertStringContainsString('Void/Reverse', $decision['recovery_hint']);
    }

    public function test_wms_purchase_flow_explains_three_way_match_and_variance_recovery(): void
    {
        $workflow = collect(WorkflowCatalog::for('wms'))->firstWhere('code', 'procure-to-pay');
        $decision = collect($workflow['decision_cards'])->firstWhere('code', 'purchase-three-way-match');

        $this->assertNull($decision['route']);
        $this->assertStringContainsString('PO, Goods Receipt และ Credit Purchase', $decision['description']);
        $this->assertStringContainsString('read-only preflight', $decision['description']);
        $this->assertStringContainsString('ห้ามใช้ Journal', $decision['block_reason']);
        $this->assertStringContainsString('จำนวนต่าง', $decision['recovery_hint']);
        $this->assertStringContainsString('ราคา/ต้นทุนต่าง', $decision['recovery_hint']);
    }

    public function test_optional_module_catalogs_are_explicit_and_asset_navigation_is_real(): void
    {
        foreach (['logistics', 'asset'] as $program) {
            $catalog = WorkflowCatalog::for($program);

            $this->assertNotEmpty($catalog);
            foreach ($catalog as $workflow) {
                foreach ($workflow['steps'] as $step) {
                    if ($program === 'asset') {
                        $this->assertNotNull($step['route']);
                    } else {
                        $this->assertNull($step['route']);
                        $this->assertNotEmpty($step['block_reason']);
                    }
                }
            }
        }
    }

    public function test_asset_workflow_exposes_posting_readiness_per_live_event(): void
    {
        $workflow = collect(WorkflowCatalog::for('asset'))->firstWhere('code', 'asset-posting-readiness');

        $this->assertNotNull($workflow);
        $this->assertSame([
            'asset.capitalization',
            'asset.addition',
            'asset.depreciation',
            'asset.impairment',
            'asset.disposal',
            'asset.write_off',
        ], collect($workflow['steps'])->pluck('event_code')->all());
        $this->assertSame('setup', $workflow['mode']);
        $this->assertSame('asset.capitalizations.index', $workflow['steps'][0]['route']);
        $this->assertSame('asset.disposals.index', $workflow['steps'][5]['route']);
    }

    public function test_finance_pos_and_purchasing_workflows_expose_live_posting_defaults(): void
    {
        $finance = collect(WorkflowCatalog::for('finance'))->firstWhere('code', 'finance-posting-readiness');
        $pos = collect(WorkflowCatalog::for('pos'))->firstWhere('code', 'sales-posting-readiness');
        $purchasing = collect(WorkflowCatalog::for('wms'))->firstWhere('code', 'purchase-posting-readiness');

        $this->assertSame(['customer_payment', 'customer_advance', 'supplier_payment'], collect($finance['steps'])->pluck('event_code')->all());
        $this->assertSame(['sales_invoice'], collect($pos['steps'])->pluck('event_code')->all());
        $this->assertSame(['supplier_invoice.inventory', 'supplier_invoice.expense'], collect($purchasing['steps'])->pluck('event_code')->all());
        $this->assertSame('setup', $finance['mode']);
        $this->assertSame('setup', $pos['mode']);
        $this->assertSame('setup', $purchasing['mode']);
    }

    public function test_all_existing_catalog_steps_expose_runtime_permission_and_navigation_contract(): void
    {
        foreach (['settings', 'wms', 'finance', 'accounting', 'pos'] as $program) {
            foreach (WorkflowCatalog::for($program) as $workflow) {
                $this->assertContains($workflow['mode'], ['setup', 'daily']);

                foreach ($workflow['steps'] as $step) {
                    $this->assertArrayHasKey('permission', $step);
                    $this->assertNotSame('', $step['permission']);
                    $this->assertArrayHasKey('route', $step);
                    $this->assertArrayHasKey('mode', $step);
                    $this->assertArrayHasKey('depends_on', $step);
                    $this->assertArrayHasKey('readiness', $step);
                    $this->assertArrayHasKey('next_action', $step);
                    $this->assertArrayHasKey('recovery_hint', $step);
                }
            }
        }
    }

    public function test_production_workflow_is_hidden_when_capability_is_disabled(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('TRADING');

        $this->assertSame([], WorkflowCatalog::for('production', new ModuleCapability($settings)));
    }

    public function test_enabled_production_catalog_is_explicit_and_does_not_require_domain_routes(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('MANUFACTURING');
        $settings->shouldReceive('value')->with('production_enabled')->andReturn(true);

        $catalog = WorkflowCatalog::for('production', new ModuleCapability($settings));

        $this->assertSame(['plan-to-produce-setup', 'plan-to-produce-daily'], array_column($catalog, 'code'));
        $this->assertCount(1, $catalog[0]['steps']);
        $this->assertCount(3, $catalog[1]['steps']);
        $this->assertNull($catalog[0]['steps'][0]['route']);
    }

    public function test_optional_workflows_keep_setup_and_daily_modes_and_asset_exposes_real_routes(): void
    {
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('value')->with('business_profile')->andReturn('MANUFACTURING');
        $settings->shouldReceive('value')->with('production_enabled')->andReturn(true);

        foreach (['production', 'logistics', 'asset'] as $program) {
            $catalog = WorkflowCatalog::for($program, new ModuleCapability($settings));
            $modes = collect($catalog)->pluck('mode')->unique()->sort()->values()->all();

            $this->assertSame(['daily', 'setup'], $modes, $program);
            foreach (collect($catalog)->flatMap(fn (array $workflow) => $workflow['steps']) as $step) {
                if ($program !== 'asset') {
                    $this->assertNull($step['route'], $program.': '.$step['label']);
                }
                $this->assertNotSame('', trim((string) ($step['recovery_hint'] ?? '')), $program.': '.$step['label']);
            }
        }
    }
}
