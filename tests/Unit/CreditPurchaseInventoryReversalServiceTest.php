<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\CreditPurchaseInventoryReversalService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CreditPurchaseInventoryReversalServiceTest extends TestCase
{
    public function test_feature_gate_does_not_run_callbacks(): void
    {
        $calls = 0;
        $this->expectException(ValidationException::class);
        $this->service()->execute($this->source(), ['reason' => 'คืนสินค้าตามใบลดหนี้'], false, null, function () use (&$calls): array { $calls++; return ['id' => 1]; });
        $this->assertSame(0, $calls);
    }

    public function test_callbacks_are_ordered_and_source_remains_unchanged(): void
    {
        $sequence = [];
        $result = $this->service()->execute(
            $this->source(), ['reason' => 'คืนสินค้าตามใบลดหนี้'], true,
            fn (callable $callback): array => $callback(),
            function () use (&$sequence): array { $sequence[] = 'movement'; return ['id' => 11]; },
            function () use (&$sequence): array { $sequence[] = 'allocation'; return ['id' => 12]; },
            function () use (&$sequence): array { $sequence[] = 'linkage'; return ['linkage_id' => 13]; },
            function () use (&$sequence): bool { $sequence[] = 'reconcile'; return true; },
        );

        $this->assertSame(['movement', 'allocation', 'linkage', 'reconcile'], $sequence);
        $this->assertSame(11, $result['movement_id']);
        $this->assertSame(12, $result['allocation_id']);
        $this->assertSame(13, $result['linkage_id']);
        $this->assertTrue($result['source_unchanged']);
    }

    public function test_failed_reconciliation_rolls_back_callback_writes(): void
    {
        $writes = [];
        $this->expectException(ValidationException::class);
        $this->service()->execute(
            $this->source(), ['reason' => 'คืนสินค้าตามใบลดหนี้'], true,
            function (callable $callback) use (&$writes): never { try { $callback(); } catch (\Throwable $exception) { $writes = []; throw $exception; } throw new \LogicException('transaction must fail'); },
            function () use (&$writes): array { $writes[] = 'movement'; return ['id' => 11]; },
            function () use (&$writes): array { $writes[] = 'allocation'; return ['id' => 12]; },
            function () use (&$writes): array { $writes[] = 'linkage'; return ['id' => 13]; },
            function (): bool { return false; },
        );
    }

    private function service(): CreditPurchaseInventoryReversalService { return new CreditPurchaseInventoryReversalService; }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'credit_document_id' => 20, 'original_document_id' => 19, 'credit_journal_id' => 70, 'movement_id' => 40, 'allocation_id' => 41, 'credit_journal_line_id' => 71, 'revision' => 0,
            'credit_document_status' => 'POSTED', 'credit_document_type' => 'CREDIT_NOTE', 'original_document_status' => 'POSTED', 'movement_status' => 'POSTED', 'allocation_status' => 'POSTED', 'allocation_cost_status' => 'FINAL', 'credit_journal_status' => 'POSTED',
            'credit_warehouse_id' => 9, 'original_warehouse_id' => 9, 'movement_warehouse_id' => 9, 'credit_supplier_id' => 12, 'original_supplier_id' => 12, 'credit_receipt_line_id' => 55, 'original_receipt_line_id' => 55,
        ];
    }
}
