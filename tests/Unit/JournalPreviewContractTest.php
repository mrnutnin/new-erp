<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class JournalPreviewContractTest extends TestCase
{
    public function test_gl_preview_is_shared_secure_and_available_to_pos_documents(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Accounting/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Accounting/Controllers/JournalEntryController.php');
        $modal = file_get_contents($root.'/resources/views/partials/journal-preview-modal.blade.php');
        $physicalSale = file_get_contents($root.'/app/Modules/Pos/Views/physical-sales/show.blade.php');

        self::assertStringContainsString("Route::get('/journal-preview/{journalEntry}'", $routes);
        self::assertStringContainsString("'permission:accounting.journal-entries.view'", $routes);
        self::assertStringContainsString('public function preview', $controller);
        self::assertStringContainsString('ensureWarehouseScope($request, $journalEntry)', $controller);
        self::assertStringContainsString('data-journal-preview-url', $modal);
        self::assertStringContainsString('data-journal-preview-urls', $modal);
        self::assertStringContainsString('GL รายได้', $physicalSale);
        self::assertStringContainsString('GL ต้นทุน', $physicalSale);
        self::assertStringContainsString('ดู GL ทั้งรายการ', $physicalSale);
    }
}
