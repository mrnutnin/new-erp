<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationDelta;
use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Services\CostRevaluationApplyService;
use App\Modules\Wms\Services\CostRevaluationJournalPostingService;
use App\Modules\Wms\Services\CostRevaluationReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CostRevaluationJournalMySqlIntegrationReadinessTest extends TestCase
{
    public function test_apply_journal_reconcile_and_complete_are_idempotent(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        if (! Schema::hasTable('wms_cost_revaluation_runs') || ! Schema::hasTable('wms_cost_revaluation_deltas')) {
            $this->markTestSkipped('ต้องกด Prepare Database ผ่าน Installer ก่อน จึงจะมี Revaluation schema สำหรับ Integration Test');
        }

        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();
        $source = CostAllocation::query()
            ->where('status', 'POSTED')
            ->whereNotNull('stock_movement_id')
            ->whereHas('movement', fn ($query) => $query->where('status', 'POSTED'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('wms_stock_balances as balances')->whereColumn('balances.warehouse_id', 'wms_cost_allocations.warehouse_id')->whereColumn('balances.item_id', 'wms_cost_allocations.item_id')->whereColumn('balances.uom_id', 'wms_cost_allocations.uom_id'))
            ->orderBy('id')
            ->first();

        if (! $period || ! $source || ! User::query()->first()) {
            $this->markTestSkipped('ยังไม่มี OPEN period, Posted allocation หรือผู้ใช้งานสำหรับ integration fixture');
        }

        try {
            app(AccountMappingService::class)->resolveForEvent('inventory.recost', 'INVENTORY');
            app(AccountMappingService::class)->resolveForEvent('inventory.recost', 'RECOST_GAIN');
        } catch (\Throwable $exception) {
            $this->markTestSkipped('ยังไม่มี account mapping สำหรับ inventory.recost: '.$exception->getMessage());
        }

        $oldGate = config('erp.inventory.revaluation_gl_posting_enabled');
        config(['erp.inventory.revaluation_gl_posting_enabled' => true]);
        DB::beginTransaction();

        try {
            $tag = 'integration:revaluation:'.bin2hex(random_bytes(8));
            $applied = CostAllocation::query()->create([
                'stock_movement_id' => $source->stock_movement_id,
                'stock_cost_layer_id' => $source->stock_cost_layer_id,
                'parent_allocation_id' => $source->id,
                'warehouse_id' => $source->warehouse_id,
                'item_id' => $source->item_id,
                'uom_id' => $source->uom_id,
                'allocation_type' => 'RECOST',
                // OUT verifies inventory projection and Journal use the
                // direction-adjusted delta rather than the raw allocation value.
                'direction' => 'OUT',
                'cost_status' => 'FINAL',
                'status' => 'PENDING',
                'method' => $source->method,
                'policy_version' => 'costing-v1',
                'revision' => $source->revision + 1,
                'quantity' => '1.00000000',
                'unit_cost' => '1.00000000',
                'value' => '1.00',
                'business_date' => $source->business_date,
                'idempotency_key' => $tag.':allocation',
                'metadata' => ['integration_test' => true],
            ]);
            $run = CostRevaluationRun::query()->create([
                'idempotency_key' => $tag,
                'root_allocation_id' => $source->id,
                'status' => 'STOCK_PROJECTED',
                'proposed_unit_cost' => '1.00000000',
                'posting_date' => $period->start_date->format('Y-m-d'),
                'nodes_affected' => 1,
                'estimated_delta_value' => '1.00',
                'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof', 'summary' => ['impact_date' => $source->business_date->format('Y-m-d')], 'variance_report' => ['status' => 'READY_FOR_REVIEW']],
                'requested_by' => User::query()->first()->id,
            ]);
            $delta = CostRevaluationDelta::query()->create([
                'run_id' => $run->id,
                'allocation_id' => $source->id,
                'applied_cost_allocation_id' => $applied->id,
                'idempotency_key' => $tag.':delta',
                'status' => 'APPLIED',
                'old_unit_cost' => (string) $source->unit_cost,
                'new_unit_cost' => '1.00000000',
                'delta_value' => '1.00',
                'impact_bucket' => 'INVENTORY_ON_HAND',
                'target_event' => 'inventory.recost',
                'target_warehouse_id' => $source->warehouse_id,
                'target_branch_id' => $source->warehouse->branch_id,
                'stock_projection_delta_value' => '-1.00',
            ]);

            $actor = User::query()->first();
            $posting = app(CostRevaluationJournalPostingService::class);
            $journal = $posting->post($run, $actor);
            $retry = $posting->post($run->fresh(), $actor);

            $this->assertSame($journal->id, $retry->id);
            $this->assertSame('GL_POSTED', $run->fresh()->status);
            $this->assertSame('POSTED', $applied->fresh()->status);
            $this->assertSame(1, $applied->fresh()->journalLineLinks()->count());

            $reconciliation = app(CostRevaluationReconciliationService::class)->check($run->fresh());
            $this->assertTrue($reconciliation['ready'], implode(', ', $reconciliation['blockers']));
            $completed = app(CostRevaluationReconciliationService::class)->complete($run->fresh());
            $this->assertSame('COMPLETED', $completed->status);
            $this->assertSame($delta->id, $completed->deltas->sole()->id);
        } finally {
            DB::rollBack();
            config(['erp.inventory.revaluation_gl_posting_enabled' => $oldGate]);
        }
    }

    public function test_classified_buckets_route_to_their_accounting_events(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        if (! Schema::hasColumn('wms_cost_revaluation_deltas', 'impact_bucket')) {
            $this->markTestSkipped('ต้องกด Prepare Database ผ่าน Installer ก่อน');
        }

        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();
        $source = CostAllocation::query()->where('status', 'POSTED')->whereNotNull('stock_movement_id')
            ->whereHas('movement', fn ($query) => $query->where('status', 'POSTED'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('wms_stock_balances as balances')->whereColumn('balances.warehouse_id', 'wms_cost_allocations.warehouse_id')->whereColumn('balances.item_id', 'wms_cost_allocations.item_id')->whereColumn('balances.uom_id', 'wms_cost_allocations.uom_id'))
            ->orderBy('id')->first();
        $actor = User::query()->first();
        if (! $period || ! $source || ! $actor || ! $source->warehouse) {
            $this->markTestSkipped('ยังไม่มี OPEN period, Posted allocation, Warehouse หรือผู้ใช้งานสำหรับ integration fixture');
        }

        $accounts = DB::table('accounts')->whereIn('code', ['13000', '13500', '14500', '42200', '42400', '52000', '52100', '52200', '52400'])->where('is_active', true)->where('is_postable', true)->pluck('id', 'code');
        if ($accounts->count() !== 9) {
            $this->markTestSkipped('Standard COA fixture ยังไม่ครบสำหรับ classified bucket test');
        }

        $oldGate = config('erp.inventory.revaluation_gl_posting_enabled');
        config(['erp.inventory.revaluation_gl_posting_enabled' => true]);
        DB::beginTransaction();

        try {
            $roleAccounts = ['INVENTORY' => '13000', 'COGS' => '52000', 'ISSUE_EXPENSE' => '52100', 'WIP' => '13500', 'FINISHED_GOODS' => '14500', 'PURCHASE_RETURN_VARIANCE' => '52200', 'ROUNDING_GAIN' => '42400', 'ROUNDING_LOSS' => '52400', 'RECOST_GAIN' => '42200', 'RECOST_LOSS' => '52200'];
            foreach ($this->classifiedCases() as $case) {
                foreach ($case['roles'] as $role) {
                    AccountMapping::query()->updateOrCreate(
                        ['event_code' => $case['event'], 'key' => $role],
                        ['account_id' => $accounts[$roleAccounts[$role]], 'is_active' => true, 'version' => 1],
                    );
                }

                $tag = 'integration:classified:'.$case['bucket'].':'.bin2hex(random_bytes(4));
                $applied = CostAllocation::query()->create([
                    'stock_movement_id' => $source->stock_movement_id, 'stock_cost_layer_id' => $source->stock_cost_layer_id,
                    'parent_allocation_id' => $source->id, 'warehouse_id' => $source->warehouse_id, 'item_id' => $source->item_id,
                    'uom_id' => $source->uom_id, 'allocation_type' => 'RECOST', 'direction' => 'IN', 'cost_status' => 'FINAL',
                    'status' => 'PENDING', 'method' => $source->method, 'policy_version' => 'costing-v1', 'revision' => $source->revision + 1,
                    'quantity' => '1.00000000', 'unit_cost' => '1.00000000', 'value' => '1.00000000',
                    'business_date' => $source->business_date, 'idempotency_key' => $tag.':allocation', 'metadata' => ['integration_test' => true],
                ]);
                $run = CostRevaluationRun::query()->create([
                    'idempotency_key' => $tag, 'root_allocation_id' => $source->id, 'status' => 'STOCK_PROJECTED',
                    'proposed_unit_cost' => '1.00000000', 'posting_date' => $period->start_date->format('Y-m-d'), 'nodes_affected' => 1,
                    'estimated_delta_value' => '1.00000000', 'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof', 'summary' => ['impact_date' => $source->business_date->format('Y-m-d')], 'variance_report' => ['status' => 'READY_FOR_REVIEW']],
                    'requested_by' => $actor->id,
                ]);
                CostRevaluationDelta::query()->create([
                    'run_id' => $run->id, 'allocation_id' => $source->id, 'applied_cost_allocation_id' => $applied->id,
                    'idempotency_key' => $tag.':delta', 'status' => 'APPLIED', 'old_unit_cost' => (string) $source->unit_cost,
                    'new_unit_cost' => '1.00000000', 'delta_value' => '1.00000000', 'impact_bucket' => $case['bucket'],
                    'target_event' => $case['event'], 'target_warehouse_id' => $source->warehouse_id,
                    'target_branch_id' => $source->warehouse->branch_id, 'stock_projection_delta_value' => $case['inventory_delta'],
                ]);

                $posted = app(CostRevaluationJournalPostingService::class)->post($run, $actor);
                $journal = $posted->deltas->sole()->appliedCostAllocation->journalEntry;
                $debit = $journal->lines()->where('debit', '>', 0)->sole();
                $credit = $journal->lines()->where('credit', '>', 0)->sole();
                self::assertSame($case['debit'], DB::table('accounts')->where('id', $debit->account_id)->value('code'), $case['bucket'].' debit');
                self::assertSame($case['credit'], DB::table('accounts')->where('id', $credit->account_id)->value('code'), $case['bucket'].' credit');
                self::assertTrue(app(CostRevaluationReconciliationService::class)->check($posted)['ready'], $case['bucket'].' reconciliation');
            }
        } finally {
            DB::rollBack();
            config(['erp.inventory.revaluation_gl_posting_enabled' => $oldGate]);
        }
    }

    public function test_apply_changes_stock_projection_only_for_ending_on_hand_delta(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        if (! Schema::hasColumn('wms_cost_revaluation_deltas', 'stock_projection_delta_value')) {
            $this->markTestSkipped('ต้องกด Prepare Database ผ่าน Installer ก่อน');
        }

        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();
        $source = CostAllocation::query()->where('status', 'POSTED')->where('quantity', '>', 0)->whereNotNull('journal_entry_id')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('wms_cost_allocation_journal_lines as proof')->whereColumn('proof.allocation_id', 'wms_cost_allocations.id')->whereColumn('proof.revision', 'wms_cost_allocations.revision'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('wms_stock_balances as balances')->whereColumn('balances.warehouse_id', 'wms_cost_allocations.warehouse_id')->whereColumn('balances.item_id', 'wms_cost_allocations.item_id')->whereColumn('balances.uom_id', 'wms_cost_allocations.uom_id'))
            ->orderBy('id')->first();
        $actor = User::query()->first();
        if (! $period || ! $source || ! $actor || ! $source->warehouse) {
            $this->markTestSkipped('ยังไม่มี OPEN period หรือ Posted allocation พร้อม Journal proof และ Stock Projection');
        }

        $accounts = DB::table('accounts')->whereIn('code', ['13000', '52000'])->where('is_active', true)->where('is_postable', true)->pluck('id', 'code');
        if ($accounts->count() !== 2) {
            $this->markTestSkipped('Standard Inventory/COGS accounts ยังไม่พร้อม');
        }

        $oldGate = config('erp.inventory.revaluation_apply_enabled');
        config(['erp.inventory.revaluation_apply_enabled' => true]);
        DB::beginTransaction();

        try {
            foreach (['COGS' => '52000', 'INVENTORY' => '13000'] as $role => $code) {
                AccountMapping::query()->updateOrCreate(['event_code' => 'inventory.revaluation.cogs', 'key' => $role], ['account_id' => $accounts[$code], 'is_active' => true, 'version' => 1]);
            }
            $balanceKey = ['warehouse_id' => $source->warehouse_id, 'item_id' => $source->item_id, 'uom_id' => $source->uom_id];
            $before = (string) DB::table('wms_stock_balances')->where($balanceKey)->value('inventory_value');

            $postingDate = $period->start_date->format('Y-m-d');
            $terminal = $this->approvedRunWithDelta($source, $actor, $postingDate, 'COGS_CONSUMED', 'inventory.revaluation.cogs', '0.00000000');
            app(CostRevaluationApplyService::class)->apply($terminal);
            self::assertSame($before, (string) DB::table('wms_stock_balances')->where($balanceKey)->value('inventory_value'));

            $onHand = $this->approvedRunWithDelta($source, $actor, $postingDate, 'INVENTORY_ON_HAND', 'inventory.recost', '1.00000000');
            app(CostRevaluationApplyService::class)->apply($onHand);
            self::assertSame(number_format((float) $before + 1, 8, '.', ''), number_format((float) DB::table('wms_stock_balances')->where($balanceKey)->value('inventory_value'), 8, '.', ''));
            self::assertSame('STOCK_PROJECTED', $onHand->fresh()->status);
            self::assertSame(1, $onHand->fresh()->nodes_scanned);
            self::assertSame('APPLY', $onHand->fresh()->runtime_checkpoint['stage']);
            self::assertNull(data_get($onHand->fresh()->runtime_checkpoint, 'apply_lease'));
            self::assertNotNull($onHand->fresh()->heartbeat_at);
        } finally {
            DB::rollBack();
            config(['erp.inventory.revaluation_apply_enabled' => $oldGate]);
        }
    }

    public function test_apply_yields_between_delta_transactions_and_resumes_from_checkpoint(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();
        $sources = CostAllocation::query()->with('warehouse')->where('status', 'POSTED')->where('quantity', '>', 0)->whereNotNull('journal_entry_id')
            ->whereHas('movement', fn ($query) => $query->where('status', 'POSTED'))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('wms_cost_allocation_journal_lines as proof')->whereColumn('proof.allocation_id', 'wms_cost_allocations.id')->whereColumn('proof.revision', 'wms_cost_allocations.revision'))
            ->orderBy('id')->limit(2)->get();
        $actor = User::query()->first();
        $accounts = DB::table('accounts')->whereIn('code', ['13000', '52000'])->where('is_active', true)->where('is_postable', true)->pluck('id', 'code');
        if (! $period || $sources->count() !== 2 || ! $actor || $accounts->count() !== 2 || $sources->contains(fn ($source): bool => ! $source->warehouse)) {
            $this->markTestSkipped('Fixture สำหรับทดสอบ Apply continuation ยังไม่พร้อม');
        }

        $oldGate = config('erp.inventory.revaluation_apply_enabled');
        config(['erp.inventory.revaluation_apply_enabled' => true]);
        DB::beginTransaction();
        try {
            foreach (['COGS' => '52000', 'INVENTORY' => '13000'] as $role => $code) {
                AccountMapping::query()->updateOrCreate(['event_code' => 'inventory.revaluation.cogs', 'key' => $role], ['account_id' => $accounts[$code], 'is_active' => true, 'version' => 1]);
            }
            $tag = 'integration:apply-yield:'.bin2hex(random_bytes(5));
            $run = CostRevaluationRun::query()->create([
                'idempotency_key' => $tag, 'root_allocation_id' => $sources->first()->id, 'status' => 'APPROVED',
                'proposed_unit_cost' => '1.00000000', 'posting_date' => $period->start_date->format('Y-m-d'),
                'nodes_affected' => 2, 'estimated_delta_value' => '2.00000000',
                'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof', 'variance_report' => ['status' => 'READY_FOR_REVIEW']],
                'requested_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
            ]);
            foreach ($sources as $source) {
                CostRevaluationDelta::query()->create([
                    'run_id' => $run->id, 'allocation_id' => $source->id, 'idempotency_key' => $tag.':'.$source->id,
                    'status' => 'PLANNED', 'old_unit_cost' => (string) $source->unit_cost, 'new_unit_cost' => '1.00000000',
                    'delta_value' => '1.00000000', 'impact_bucket' => 'COGS_CONSUMED', 'target_event' => 'inventory.revaluation.cogs',
                    'target_warehouse_id' => $source->warehouse_id, 'target_branch_id' => $source->warehouse->branch_id,
                    'stock_projection_delta_value' => '0.00000000',
                ]);
            }

            $first = app(CostRevaluationApplyService::class)->applyChunk($run, 1, 20);
            self::assertSame('WAITING_CONTINUATION', $first['run']->status);
            self::assertSame(1, $first['processed']);
            self::assertSame(1, $run->deltas()->where('status', 'APPLIED')->count());
            self::assertNull(data_get($first['run']->runtime_checkpoint, 'apply_lease'));

            $second = app(CostRevaluationApplyService::class)->applyChunk($first['run'], 1, 20);
            self::assertSame('STOCK_PROJECTED', $second['run']->status);
            self::assertSame(2, $run->deltas()->where('status', 'APPLIED')->count());
            self::assertSame($run->deltas()->max('id'), data_get($second['run']->runtime_checkpoint, 'last_delta_id'));
        } finally {
            DB::rollBack();
            config(['erp.inventory.revaluation_apply_enabled' => $oldGate]);
        }
    }

    private function approvedRunWithDelta(CostAllocation $source, User $actor, string $postingDate, string $bucket, string $event, string $projectionDelta): CostRevaluationRun
    {
        $tag = 'integration:apply:'.$bucket.':'.bin2hex(random_bytes(5));
        $run = CostRevaluationRun::query()->create([
            'idempotency_key' => $tag, 'root_allocation_id' => $source->id, 'status' => 'APPROVED',
            'proposed_unit_cost' => '1.00000000', 'posting_date' => $postingDate, 'nodes_affected' => 1,
            'estimated_delta_value' => '1.00000000', 'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof', 'summary' => ['impact_date' => $source->business_date->format('Y-m-d')], 'variance_report' => ['status' => 'READY_FOR_REVIEW']],
            'requested_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
        ]);
        CostRevaluationDelta::query()->create([
            'run_id' => $run->id, 'allocation_id' => $source->id, 'idempotency_key' => $tag.':delta', 'status' => 'PLANNED',
            'old_unit_cost' => (string) $source->unit_cost, 'new_unit_cost' => '1.00000000', 'delta_value' => '1.00000000',
            'impact_bucket' => $bucket, 'target_event' => $event, 'target_warehouse_id' => $source->warehouse_id,
            'target_branch_id' => $source->warehouse->branch_id, 'stock_projection_delta_value' => $projectionDelta,
        ]);

        return $run;
    }

    /** @return list<array{bucket:string,event:string,roles:list<string>,debit:string,credit:string,inventory_delta:string}> */
    private function classifiedCases(): array
    {
        return [
            ['bucket' => 'INVENTORY_ON_HAND', 'event' => 'inventory.recost', 'roles' => ['INVENTORY', 'RECOST_GAIN'], 'debit' => '13000', 'credit' => '42200', 'inventory_delta' => '1.00000000'],
            ['bucket' => 'COGS_CONSUMED', 'event' => 'inventory.revaluation.cogs', 'roles' => ['COGS', 'INVENTORY'], 'debit' => '52000', 'credit' => '13000', 'inventory_delta' => '-1.00000000'],
            ['bucket' => 'ISSUE_EXPENSE_CONSUMED', 'event' => 'inventory.revaluation.issue_expense', 'roles' => ['ISSUE_EXPENSE', 'INVENTORY'], 'debit' => '52100', 'credit' => '13000', 'inventory_delta' => '-1.00000000'],
            ['bucket' => 'WIP_CONSUMED', 'event' => 'production.revaluation.wip', 'roles' => ['WIP', 'INVENTORY'], 'debit' => '13500', 'credit' => '13000', 'inventory_delta' => '-1.00000000'],
            ['bucket' => 'FINISHED_GOODS_BRIDGE', 'event' => 'production.revaluation.finished_goods', 'roles' => ['FINISHED_GOODS', 'WIP'], 'debit' => '14500', 'credit' => '13500', 'inventory_delta' => '1.00000000'],
            ['bucket' => 'RETURN_BRIDGE', 'event' => 'inventory.revaluation.sales_return', 'roles' => ['INVENTORY', 'COGS'], 'debit' => '13000', 'credit' => '52000', 'inventory_delta' => '1.00000000'],
            ['bucket' => 'RETURN_BRIDGE', 'event' => 'inventory.revaluation.issue_return', 'roles' => ['INVENTORY', 'ISSUE_EXPENSE'], 'debit' => '13000', 'credit' => '52100', 'inventory_delta' => '1.00000000'],
            ['bucket' => 'RETURN_BRIDGE', 'event' => 'production.revaluation.material_return', 'roles' => ['INVENTORY', 'WIP'], 'debit' => '13000', 'credit' => '13500', 'inventory_delta' => '1.00000000'],
            ['bucket' => 'PURCHASE_RETURN_CONSUMED', 'event' => 'purchasing.revaluation.return_cost', 'roles' => ['PURCHASE_RETURN_VARIANCE', 'INVENTORY'], 'debit' => '52200', 'credit' => '13000', 'inventory_delta' => '-1.00000000'],
            ['bucket' => 'ROUNDING_RESIDUAL', 'event' => 'inventory.revaluation.rounding', 'roles' => ['INVENTORY', 'ROUNDING_GAIN'], 'debit' => '13000', 'credit' => '42400', 'inventory_delta' => '1.00000000'],
        ];
    }
}
