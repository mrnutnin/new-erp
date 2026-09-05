<?php

namespace Tests\Unit;

use App\Modules\Platform\Controllers\DocumentTemplateController;
use Tests\TestCase;

final class DocumentTemplateBuilderUiContractTest extends TestCase
{
    public function test_builder_is_reachable_from_settings_and_uses_whitelisted_definition_input(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Settings/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Platform/Controllers/DocumentTemplateController.php'));
        $view = file_get_contents(base_path('app/Modules/Platform/Views/document-templates/index.blade.php'));
        $render = file_get_contents(base_path('app/Modules/Platform/Services/DocumentTemplateRenderService.php'));
        $pdf = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php'));
        $registry = file_get_contents(base_path('app/Modules/Platform/Support/DocumentFieldRegistry.php'));

        self::assertStringContainsString("/document-templates", $routes);
        self::assertStringContainsString('settings.document-templates.view', $routes);
        self::assertStringContainsString('settings.document-templates.update', $routes);
        self::assertStringContainsString('document-templates.index', file_get_contents(base_path('app/Modules/Settings/Views/partials/sidebar.blade.php')));
        self::assertStringContainsString('DocumentFieldRegistry::fields', $controller);
        self::assertStringContainsString('NormalizedDocumentPayloadContract::normalize', $controller);
        self::assertStringContainsString("view('Platform::document-templates.render'", $render);
        self::assertStringContainsString('renderDefault', $pdf);
        self::assertStringContainsString('$renderer->render($templateHtml)', $pdf);
        self::assertStringContainsString('template-sections', $view);
        self::assertStringContainsString('add-template-section', $view);
        self::assertStringContainsString('template-definition', $view);
        self::assertStringContainsString('dragstart', $view);
        self::assertStringContainsString('dragover', $view);
        self::assertStringContainsString('Publish', $view);
        self::assertStringContainsString('แก้ไข Draft', $view);
        self::assertStringContainsString('สร้าง Version ใหม่', $view);
        self::assertStringContainsString('Archive', $view);
        self::assertStringContainsString('document-templates.update', $routes);
        self::assertStringContainsString('document-templates.new-version', $routes);
        self::assertStringContainsString('document-templates.archive', $routes);
        self::assertStringContainsString('document-templates.create', $routes);
        self::assertStringContainsString('document-templates.edit', $routes);
        self::assertStringContainsString('signatures.prepared_by', $registry);
        self::assertStringContainsString('ลายเซ็น', $view);
        self::assertStringContainsString('Preview PDF', $view);
        self::assertStringContainsString("trigger('click')", $view);
        self::assertStringContainsString('submit.templateAjax', $view);
        self::assertStringContainsString('dataType:\'json\'', $view);
        self::assertStringContainsString('template-preview-button', $view);
        self::assertStringContainsString("document-templates.preview", $routes);
        self::assertStringContainsString('document-templates.preview-version', $routes);
        self::assertStringContainsString('DocumentPdfRenderer', $controller);
        self::assertStringContainsString("'Content-Type' => 'application/pdf'", $controller);
        self::assertTrue(class_exists(DocumentTemplateController::class));
    }
}
