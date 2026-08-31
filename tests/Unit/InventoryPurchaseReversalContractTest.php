<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryPurchaseReversalContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPurchaseReversalContractTest extends TestCase
{
    public function test_builds_immutable_reversal_revision_and_stable_key(): void
    {
        $plan = InventoryPurchaseReversalContract::plan($this->source(), ['reason' => 'แก้ไขรับสินค้าผิดรายการ', 'date' => '2026-08-21']);

        $this->assertSame('reversal:purchase:9:movement:202:revision:1', $plan['idempotency_key']);
        $this->assertSame(1, $plan['revision']);
        $this->assertSame('NO_UPDATE', $plan['immutability']['source_movement']);
        $this->assertContains('reconcile_zero', $plan['steps']);
    }

    public function test_retry_policy_is_reuse_same_hash_and_reject_changed_hash(): void
    {
        $plan = InventoryPurchaseReversalContract::plan($this->source(), ['reason' => 'เหตุผลเดิม']);

        $this->assertSame('REUSE', $plan['idempotency']['same_key_same_hash']);
        $this->assertSame('REJECT', $plan['idempotency']['same_key_different_hash']);
        $this->assertSame($plan['payload_hash'], InventoryPurchaseReversalContract::plan($this->source(), ['reason' => 'เหตุผลเดิม'])['payload_hash']);
    }

    public function test_rejects_non_posted_or_already_reversed_source(): void
    {
        $this->expectException(ValidationException::class);
        InventoryPurchaseReversalContract::plan([...$this->source(), 'movement_status' => 'DRAFT'], ['reason' => 'ย้อนรายการ']);
    }

    public function test_rejects_missing_reason_and_already_reversed_allocation(): void
    {
        try {
            InventoryPurchaseReversalContract::plan($this->source(), []);
            $this->fail('Expected reason validation');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(ValidationException::class);
        InventoryPurchaseReversalContract::plan([...$this->source(), 'allocation_status' => 'REVERSED'], ['reason' => 'ย้อนรายการ']);
    }

    public function test_multi_line_reversal_is_fail_closed(): void
    {
        $this->expectException(ValidationException::class);
        InventoryPurchaseReversalContract::assertSingleLine(2, 2);
    }

    private function source(): array
    {
        return ['document_id' => 9, 'journal_id' => 101, 'movement_id' => 202, 'allocation_id' => 303, 'revision' => 0, 'document_status' => 'POSTED', 'movement_status' => 'POSTED', 'allocation_status' => 'PENDING'];
    }
}
