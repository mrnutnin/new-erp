<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Wms\Services\InventoryPurchaseProductionAdapter;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryPurchaseIntegrationFixture;
use Tests\TestCase;

/**
 * Opt-in local-MySQL contract placeholder. The normal PHPUnit suite uses an
 * in-memory SQLite database and must never mutate the developer's ERP data.
 * Run this only in a dedicated local integration process after its preflight
 * fixture has been approved; it is intentionally skipped by default.
 */
final class InventoryPurchaseMySqlIntegrationReadinessTest extends TestCase
{
    public function test_local_mysql_inventory_purchase_posting_rolls_back_the_full_chain(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('1', env('ERP_RUN_MYSQL_INTEGRATION'));
        InventoryPurchaseIntegrationFixture::assertReady();
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User fixture ใน dedicated MySQL DB');
        }
        $before = [
            'journals' => DB::table('journal_entries')->count(),
            'movements' => DB::table('wms_stock_movements')->count(),
            'allocations' => DB::table('wms_cost_allocations')->count(),
            'links' => DB::table('wms_cost_allocation_journal_lines')->count(),
        ];
        $previous = config('erp.inventory.purchase_posting_enabled');
        DB::beginTransaction();
        try {
            $document = InventoryPurchaseIntegrationFixture::createApprovedPurchase($actor);
            $warehouse = Warehouse::query()->findOrFail($document->warehouse_id);
            $baselineBlockers = InventoryPurchaseIntegrationFixture::baselineBlockers((int) $document->warehouse_id);
            if ($baselineBlockers !== []) {
                $this->markTestSkipped('Baseline reconciliation ของ Warehouse fixture ยังไม่สะอาด: '.implode('; ', $baselineBlockers));
            }
            config(['erp.inventory.purchase_posting_enabled' => true]);
            $posted = app(InventoryPurchaseProductionAdapter::class)->post($document, $warehouse, $actor, null, true);
            $this->assertSame('POSTED', $posted->status);
            $this->assertNotNull($posted->journal_entry_id);
            $this->assertSame(0.0, (float) DB::table('journal_entry_lines')->where('journal_entry_id', $posted->journal_entry_id)->sum(DB::raw('debit - credit')));
            $movementIds = DB::table('wms_stock_movements')->where('source_type', 'PURCHASING')->where('source_id', (string) $document->id)->pluck('id');
            $this->assertGreaterThan(0, $movementIds->count());
            $allocationIds = DB::table('wms_cost_allocations')->whereIn('stock_movement_id', $movementIds)->where('status', '!=', 'REVERSED')->pluck('id');
            $this->assertGreaterThan(0, $allocationIds->count());
            $this->assertSame(0, DB::table('wms_cost_allocations')->whereIn('id', $allocationIds)->where(fn ($q) => $q->where('cost_status', '!=', 'FINAL')->orWhere('status', '!=', 'POSTED'))->count());
            $this->assertSame($allocationIds->count(), DB::table('wms_cost_allocation_journal_lines')->whereIn('allocation_id', $allocationIds)->count());
            $this->assertGreaterThan($before['journals'], DB::table('journal_entries')->count());
            $this->assertGreaterThan($before['movements'], DB::table('wms_stock_movements')->count());
            $this->assertGreaterThan($before['allocations'], DB::table('wms_cost_allocations')->count());
            $this->assertGreaterThan($before['links'], DB::table('wms_cost_allocation_journal_lines')->count());
        } finally {
            DB::rollBack();
            config(['erp.inventory.purchase_posting_enabled' => $previous]);
        }

        $this->assertSame($before, [
            'journals' => DB::table('journal_entries')->count(),
            'movements' => DB::table('wms_stock_movements')->count(),
            'allocations' => DB::table('wms_cost_allocations')->count(),
            'links' => DB::table('wms_cost_allocation_journal_lines')->count(),
        ]);
    }
}
