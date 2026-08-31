<?php

namespace Tests\Unit;

use App\Modules\Finance\Controllers\DocumentSequenceController;
use App\Modules\Finance\Requests\SaveDocumentSequenceRequest;
use PHPUnit\Framework\TestCase;

final class DocumentSequenceGlobalSettingsContractTest extends TestCase
{
    public function test_global_document_sequence_management_has_no_selected_warehouse_dependency(): void
    {
        $controller = file_get_contents((new \ReflectionClass(DocumentSequenceController::class))->getFileName());
        $request = file_get_contents((new \ReflectionClass(SaveDocumentSequenceRequest::class))->getFileName());

        self::assertStringNotContainsString('selectedWarehouse', $controller);
        self::assertStringNotContainsString('selectedWarehouse', $request);
        self::assertStringContainsString("->whereNull('warehouse_id')", $request);
    }

    public function test_document_sequence_routes_are_available_from_global_settings_with_legacy_finance_redirects(): void
    {
        $root = dirname(__DIR__, 2);
        $settingsRoutes = file_get_contents($root.'/app/Modules/Settings/Routes/web.php');
        $financeRoutes = file_get_contents($root.'/app/Modules/Finance/Routes/web.php');

        foreach (['index', 'data', 'edit', 'update'] as $action) {
            self::assertStringContainsString("name('document-sequences.{$action}')", $settingsRoutes);
        }

        self::assertStringNotContainsString("name('document-sequences.create')", $settingsRoutes);
        self::assertStringNotContainsString("name('document-sequences.destroy')", $settingsRoutes);

        self::assertStringContainsString("Route::redirect('/document-sequences', '/settings/document-sequences')", $financeRoutes);
        self::assertStringContainsString("Route::redirect('/document-sequences/data', '/settings/document-sequences/data')", $financeRoutes);
    }
}
