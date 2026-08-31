<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\RecostGlPostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class RecostGlPostingContractTest extends TestCase
{
    private function input(array $overrides = []): array
    {
        return array_merge(['warehouse_id' => 1, 'item_id' => 2, 'recost_request_id' => 3, 'parent_allocation_id' => 4, 'revision' => 1, 'delta_value' => '12.50', 'business_date' => '2026-08-22', 'source_type' => 'WMS_RECOST', 'period_open' => true, 'reconciliation_ready' => true, 'status' => 'PENDING'], $overrides);
    }

    public function test_positive_delta_has_gain_mapping_and_stable_identity(): void
    {
        $result = RecostGlPostingContract::preflight($this->input());
        $this->assertSame(['INVENTORY_DEFAULT', 'INVENTORY_RECOST_GAIN'], $result['mapping_keys']);
        $this->assertSame('INCREASE_INVENTORY', $result['delta_direction']);
        $this->assertSame($result['idempotency_hash'], RecostGlPostingContract::preflight($this->input())['idempotency_hash']);
        $this->assertFalse($result['creates_journal']);
    }

    public function test_negative_delta_has_loss_mapping(): void
    {
        $result = RecostGlPostingContract::preflight($this->input(['delta_value' => '-4.25']));
        $this->assertSame(['INVENTORY_DEFAULT', 'INVENTORY_RECOST_LOSS'], $result['mapping_keys']);
        $this->assertSame('DECREASE_INVENTORY', $result['delta_direction']);
    }

    public function test_pending_allocation_with_null_journal_is_allowed_by_preflight(): void
    {
        $result = RecostGlPostingContract::preflight($this->input(['journal_entry_id' => null]));
        $this->assertSame('inventory.recost', $result['event_code']);
    }

    public function test_closed_period_or_unreconciled_source_fails_closed(): void
    {
        $this->expectException(ValidationException::class);
        RecostGlPostingContract::preflight($this->input(['period_open' => false]));
    }
}
