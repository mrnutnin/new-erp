<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakeLatestQuotationDataTableContractTest extends TestCase
{
    public function test_latest_quotation_eager_load_qualifies_columns(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Controllers/SalesIntakeController.php');

        self::assertStringContainsString("quotation:sales_quotations.id,sales_quotations.source_sales_intake_id,sales_quotations.document_number,sales_quotations.status", $controller);
    }
}
