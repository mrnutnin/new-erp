<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\StockMovementContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StockMovementContractTest extends TestCase
{
    private function valid(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => 1, 'item_id' => 2, 'uom_id' => 3,
            'movement_type' => 'RECEIPT', 'direction' => 'IN', 'quantity' => '1.23456789',
            'base_quantity' => '12.34567890', 'business_date' => '2026-08-21', 'idempotency_key' => 'receipt:1',
        ], $overrides);
    }

    public function test_normalizes_decimal_quantity_without_float(): void
    {
        $result = StockMovementContract::normalize($this->valid());
        $this->assertSame('1.23456789', $result['quantity']);
        $this->assertSame('12.34567890', $result['base_quantity']);
    }

    public function test_transfer_requires_pairing_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockMovementContract::normalize($this->valid(['movement_type' => 'TRANSFER']));
    }

    public function test_transfer_normalizes_pairing_key_and_matches_storage_limit(): void
    {
        $result = StockMovementContract::normalize($this->valid([
            'movement_type' => 'TRANSFER',
            'transfer_key' => '  transfer:1:line:2  ',
        ]));

        $this->assertSame('transfer:1:line:2', $result['transfer_key']);
    }

    public function test_transfer_rejects_pairing_key_longer_than_storage_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockMovementContract::normalize($this->valid([
            'movement_type' => 'TRANSFER',
            'transfer_key' => str_repeat('x', 101),
        ]));
    }

    public function test_rejects_zero_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockMovementContract::normalize($this->valid(['quantity' => '0']));
    }
}
