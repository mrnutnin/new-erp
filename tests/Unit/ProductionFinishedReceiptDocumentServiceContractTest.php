<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ProductionFinishedReceiptDocumentServiceContractTest extends TestCase
{
    public function test_finished_receipt_writes_are_reusable_outside_the_wms_controller(): void
    {
        $service = file_get_contents(base_path('app/Modules/Wms/Services/ProductionFinishedReceiptDocumentService.php'));
        $controller = file_get_contents(base_path('app/Modules/Wms/Controllers/ProductionFinishedReceiptController.php'));

        foreach (['public function create(', 'public function update(', 'public function approve(', 'DocumentSequenceService', 'AuditLogger', 'ManualProductionReceiptContract::assert', 'lockForUpdate()', 'syncSources(', "\$values['idempotency_key']", "\$values['line_idempotency_prefix']", 'assertSameRetryPayload(', 'Idempotency key นี้ถูกใช้กับข้อมูลอื่นแล้ว'] as $contract) {
            self::assertStringContainsString($contract, $service);
        }
        self::assertStringContainsString('ProductionFinishedReceiptDocumentService $documents', $controller);
        self::assertStringContainsString('$documents->create(', $controller);
        self::assertStringContainsString('$documents->update(', $controller);
        self::assertStringContainsString('$documents->approve(', $controller);
        self::assertStringNotContainsString('InventoryAdjustment::query()->create', $controller);
        self::assertStringNotContainsString('DocumentSequence::query()', $controller);
    }
}
