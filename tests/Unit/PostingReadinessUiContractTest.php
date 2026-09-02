<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PostingReadinessUiContractTest extends TestCase
{
    public function test_live_document_pages_use_readiness_without_replacing_server_posting(): void
    {
        $root = dirname(__DIR__, 2);
        $pairs = [
            'app/Modules/Asset/Services/AssetCapitalizationService.php' => 'postReadiness',
            'app/Modules/Asset/Services/AssetDepreciationRunService.php' => 'postReadiness',
            'app/Modules/Asset/Services/AssetDisposalService.php' => 'postReadiness',
            'app/Modules/Asset/Services/AssetImpairmentService.php' => 'postReadiness',
            'app/Modules/Pos/Services/SalesDocumentPostingService.php' => 'postReadiness',
            'app/Modules/Pos/Services/PhysicalSalePostingService.php' => 'postReadiness',
            'app/Modules/Pos/Services/CommissionPayoutService.php' => 'postReadiness',
            'app/Modules/Finance/Services/SettlementPostingService.php' => 'postReadiness',
            'app/Modules/Wms/Services/PurchaseDocumentPostingService.php' => 'postReadiness',
            'app/Modules/Wms/Services/InventoryAdjustmentPostingService.php' => 'documentPostReadiness',
        ];

        foreach ($pairs as $path => $method) {
            self::assertStringContainsString('function '.$method, (string) file_get_contents($root.'/'.$path), $path);
        }

        foreach ([
            'app/Modules/Asset/Views/capitalizations/show.blade.php',
            'app/Modules/Asset/Views/depreciations/show.blade.php',
            'app/Modules/Asset/Views/disposals/show.blade.php',
            'app/Modules/Asset/Views/impairments/show.blade.php',
            'app/Modules/Pos/Views/sales-documents/show.blade.php',
            'app/Modules/Pos/Views/physical-sales/show.blade.php',
            'app/Modules/Pos/Views/receipts/show.blade.php',
            'app/Modules/Finance/Views/commission-payouts/payout-show.blade.php',
            'app/Modules/Wms/Views/purchase-documents/show.blade.php',
            'app/Modules/Wms/Views/inventory-adjustments/documents/show.blade.php',
        ] as $path) {
            $view = (string) file_get_contents($root.'/'.$path);
            self::assertStringContainsString('postReadiness', $view, $path);
            self::assertStringContainsString('disabled', $view, $path);
        }
    }
}
