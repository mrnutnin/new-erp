<?php

namespace Tests\Unit;

use App\Modules\Wms\Controllers\PurchaseDocumentController;
use Tests\TestCase;

final class PurchaseDocumentInventoryPostGateTest extends TestCase
{
    public function test_inventory_callback_is_hidden_and_rejected_for_service_purchase(): void
    {
        $controller = file_get_contents((new \ReflectionClass(PurchaseDocumentController::class))->getFileName());
        $view = file_get_contents(base_path('app/Modules/Wms/Views/purchase-documents/show.blade.php'));

        self::assertStringContainsString('abort_unless($this->isInventoryPurchase($document->load([\'lines.item\', \'lines.receiptAllocations\'])), 404)', $controller);
        self::assertStringContainsString('&& $isInventoryPurchase &&', $view);
    }
}
