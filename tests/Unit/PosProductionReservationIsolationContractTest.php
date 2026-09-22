<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PosProductionReservationIsolationContractTest extends TestCase
{
    public function test_pos_keeps_legacy_stock_posting_when_production_is_disabled(): void
    {
        $posting = file_get_contents(base_path('app/Modules/Pos/Services/PhysicalSalePostingService.php'));
        $reservation = file_get_contents(base_path('app/Modules/Wms/Models/StockReservation.php'));

        self::assertStringContainsString('$consumeProductionReservations = $this->usesProductionReservations($sale, $lines)', $posting);
        self::assertStringContainsString("if (\$sale->source_type !== 'SALES_ORDER')", $posting);
        self::assertStringContainsString('if ($this->capabilities->isEnabled(ModuleCapability::PRODUCTION))', $posting);
        self::assertStringContainsString("->where('status', 'OPEN')", $posting);
        self::assertStringContainsString('$reservation = $consumeProductionReservations', $posting);
        self::assertStringContainsString('$this->reservations->consume($reservation, (string) $intent[\'base_quantity\'], $movement', $posting);
        self::assertStringContainsString('fn ($draft) => $this->stock->postWithinTransaction($draft)', $posting);
        self::assertStringNotContainsString('StockBalance::query()', $posting);
        self::assertStringContainsString("} else {\n                    \$movement = \$this->stock->postWithinTransaction(\$movement);", $posting);
        self::assertStringContainsString('private function productionReservation(', $posting);
        self::assertStringContainsString("public const SOURCE_SALES_ORDER_LINE = 'SALES_ORDER_LINE'", $reservation);
    }
}
