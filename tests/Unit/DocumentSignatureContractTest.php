<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DocumentSignatureContractTest extends TestCase
{
    public function test_pdf_documents_use_the_shared_immutable_signature_component(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'app/Modules/Accounting/Views/pdf/journal-voucher.blade.php',
            'app/Modules/Purchasing/Views/pdf/purchase-requisition.blade.php',
            'app/Modules/Purchasing/Views/pdf/purchase-order.blade.php',
            'app/Modules/Purchasing/Views/pdf/goods-receipt.blade.php',
            'app/Modules/Purchasing/Views/pdf/purchase-document.blade.php',
            'app/Modules/Purchasing/Views/pdf/landed-cost.blade.php',
            'app/Modules/Pos/Views/pdf/physical-sale.blade.php',
            'app/Modules/Pos/Views/pdf/receipt.blade.php',
            'app/Modules/Pos/Views/pdf/advance-deposit.blade.php',
            'app/Modules/Pos/Views/pdf/billing-note.blade.php',
            'app/Modules/Pos/Views/pdf/sales-intake.blade.php',
            'app/Modules/Pos/Views/pdf/sales-rfq.blade.php',
            'app/Modules/Pos/Views/pdf/sales-quotation.blade.php',
            'app/Modules/Pos/Views/pdf/sales-order.blade.php',
            'app/Modules/Pos/Views/pdf/sales-document.blade.php',
            'app/Modules/Pos/Views/pdf/sales-return.blade.php',
        ];

        foreach ($files as $file) {
            $contents = file_get_contents($root.'/'.$file);
            self::assertStringContainsString('x-platform::pdf-signatures', $contents, $file);
            self::assertStringNotContainsString('<table class="pdf-signatures', $contents, $file);
        }

        $migration = file_get_contents($root.'/database/migrations/2026_09_16_190000_create_document_signature_snapshots.php');
        self::assertStringContainsString("Schema::create('document_signature_snapshots'", $migration);
        self::assertStringContainsString('signature_checksum', $migration);

        $installer = file_get_contents($root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        self::assertStringContainsString("'document_signature_snapshots'", $installer);
        foreach (['position', 'signature_checksum', 'signature_mime_type'] as $column) {
            self::assertStringContainsString("'{$column}'", $installer);
        }

        $media = file_get_contents($root.'/app/Modules/Platform/Services/UserMediaService.php');
        self::assertStringContainsString('DocumentSignatureSnapshot::query()', $media);
        self::assertStringContainsString("\$values['signature_checksum']", $media);
    }
}
