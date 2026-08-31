<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakePromotionSelectionContractTest extends TestCase
{
    public function test_intake_posts_only_promotion_ids_and_revalidates_them_server_side(): void
    {
        $base = dirname(__DIR__, 2);
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/SalesIntakeController.php');
        $request = file_get_contents($base.'/app/Modules/Pos/Requests/SaveSalesIntakeRequest.php');
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $form = file_get_contents($base.'/app/Modules/Pos/Views/sales-intakes/form.blade.php');
        $styles = file_get_contents($base.'/public/css/app.css');

        self::assertStringContainsString("'document_promotion_id' => ['nullable', 'integer', 'min:1']", $request);
        self::assertStringContainsString("'lines.*.promotion_id' => ['nullable', 'integer', 'min:1']", $request);
        self::assertStringContainsString("Route::get('/sales-intakes/promotion-options'", $routes);
        self::assertStringContainsString('function promotionOptions', $controller);
        self::assertStringContainsString('resolveLinePromotion', $controller);
        self::assertStringContainsString('resolveLinePromotions', $controller);
        self::assertStringContainsString('resolveDocumentAll', $controller);
        self::assertStringContainsString("'THB', \$promotionId", $controller);
        self::assertStringContainsString('resolveDocumentPromotion', $controller);
        self::assertStringContainsString('PromotionStack::assertValid', $controller);
        self::assertStringNotContainsString('PricingResolver', $controller);
        self::assertStringContainsString("'pricing_snapshot' => \$promotion ?? \$priceSnapshot", $controller);
        self::assertStringContainsString("'promotion_snapshot' => \$documentPromotion", $controller);
        self::assertStringContainsString('name="document_promotion_id"', $form);
        self::assertStringContainsString('name="lines[{{ $i }}][promotion_id]"', $form);
        self::assertStringContainsString('loadPromotion', $form);
        self::assertStringContainsString('sales-intake-lines-table', $form);
        self::assertStringContainsString('.sales-intake-lines-table .sales-intake-item-cell', $styles);
        self::assertStringContainsString('min-width: 420px', $styles);
        self::assertStringContainsString('templateSelection:data', $form);
    }
}
