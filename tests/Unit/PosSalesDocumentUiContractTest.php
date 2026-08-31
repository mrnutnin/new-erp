<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosSalesDocumentUiContractTest extends TestCase
{
    public function test_sales_document_detail_pages_use_the_shared_status_badge(): void
    {
        $base = dirname(__DIR__, 2);

        foreach (['sales-intakes/show', 'sales-rfqs/show', 'sales-quotations/show', 'sales-orders/show'] as $template) {
            $view = file_get_contents("{$base}/app/Modules/Pos/Views/{$template}.blade.php");

            self::assertStringContainsString('Pos::partials.document-status-badge', $view);
            self::assertStringContainsString('app-eyebrow', $view);
            self::assertStringContainsString('<h1 class="h2 mb-2">', $view);
        }
    }
}
