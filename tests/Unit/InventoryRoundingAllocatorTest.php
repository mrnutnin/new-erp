<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryRoundingAllocator;
use Tests\TestCase;

final class InventoryRoundingAllocatorTest extends TestCase
{
    public function test_allocates_rounded_document_total_without_losing_exact_values(): void
    {
        $this->assertSame(['0.03', '0.03', '0.02'], InventoryRoundingAllocator::allocate(['0.025', '0.025', '0.025']));
        $this->assertSame('0.08', (string) array_sum(array_map('floatval', InventoryRoundingAllocator::allocate(['0.025', '0.025', '0.025']))));
    }
}
