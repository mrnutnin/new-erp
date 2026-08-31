<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Services\InventoryPurchaseProductionAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Read-only smoke against the committed local Gate 2/Purchase evidence. */
final class InventoryPurchaseEnabledSmokeReadinessTest extends TestCase
{
    public function test_enabled_purchase_post_and_retry_do_not_duplicate_the_committed_chain(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_SMOKE') !== '1') {
            $this->markTestSkipped('ต้องรัน local MySQL smoke ด้วย ERP_RUN_MYSQL_SMOKE=1 เท่านั้น');
        }
        $invoice = PurchaseDocument::query()->where('document_number', 'PI-INT-FU5SNJLMRX45')->where('status', 'POSTED')->first();
        $actor = User::query()->first();
        if (! $invoice || ! $actor) {
            $this->markTestSkipped('ไม่พบ committed local Purchase Invoice evidence หรือ User');
        }
        $warehouse = $invoice->warehouse()->firstOrFail();
        $journal = DB::table('journal_entries')->where('id', $invoice->journal_entry_id)->where('status', 'POSTED')->where('source_type', 'PURCHASING')->where('source_event', 'supplier_invoice.inventory')->where('source_id', (string) $invoice->id)->first();
        $movements = DB::table('wms_stock_movements')->where('source_type', 'PURCHASING')->where('source_id', (string) $invoice->id)->where('status', 'POSTED')->get();
        $allocations = DB::table('wms_cost_allocations')->whereIn('stock_movement_id', $movements->pluck('id'))->where('status', 'POSTED')->where('cost_status', 'FINAL')->get();
        $this->assertNotNull($journal);
        $this->assertCount(1, $movements);
        $this->assertCount(1, $allocations);
        $this->assertSame(1, DB::table('wms_cost_allocation_journal_lines')->where('allocation_id', $allocations->sole()->id)->count());
        $before = $this->counts();
        $retry = app(InventoryPurchaseProductionAdapter::class)->post($invoice, $warehouse, $actor, null, true);
        $this->assertSame((int) $invoice->journal_entry_id, (int) $retry->journal_entry_id);
        $this->assertSame($before, $this->counts());

        try {
            app(InventoryPurchaseProductionAdapter::class)->post($invoice, $warehouse, $actor, null, true, '2099-01-01');
            $this->fail('Expected posted-date identity failure');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('posting_date', $exception->errors());
        }
        $this->assertSame($before, $this->counts());
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'journals' => DB::table('journal_entries')->count(),
            'movements' => DB::table('wms_stock_movements')->count(),
            'allocations' => DB::table('wms_cost_allocations')->count(),
            'links' => DB::table('wms_cost_allocation_journal_lines')->count(),
        ];
    }
}
