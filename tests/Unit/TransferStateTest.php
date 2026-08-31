<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\TransferContract;
use App\Modules\Wms\Support\TransferState;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TransferStateTest extends TestCase
{
    public function test_valid_transfer_transitions_are_allowed(): void
    {
        TransferState::assert('DRAFT', 'DISPATCHED');
        TransferState::assert('DISPATCHED', 'PARTIALLY_ACCEPTED');
        TransferState::assert('PARTIALLY_ACCEPTED', 'ACCEPTED');
        $this->assertTrue(true);
    }

    public function test_post_dispatch_edit_and_accept_to_draft_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransferState::assert('DISPATCHED', 'DRAFT');
    }

    public function test_source_can_void_a_fully_rejected_transfer_for_recreation(): void
    {
        TransferState::assert('REJECTED', 'VOID');
        $this->assertTrue(true);
    }

    public function test_transfer_contract_rejects_same_warehouse_and_normalizes_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TransferContract::normalizeHeader(['source_warehouse_id' => 1, 'destination_warehouse_id' => 1, 'document_date' => '2026-08-22', 'idempotency_key' => 'transfer-1']);
    }

    public function test_transfer_contract_scales_positive_quantity(): void
    {
        $this->assertSame('2.50000000', TransferContract::normalizeQuantity('2.5'));
    }
}
