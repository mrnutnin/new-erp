<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WmsDocumentPhotoEvidenceContractTest extends TestCase
{
    public function test_issue_and_receipt_documents_support_optional_private_photo_evidence(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Wms/Controllers/DocumentPhotoController.php');
        $request = file_get_contents($root.'/app/Modules/Wms/Requests/StoreDocumentPhotosRequest.php');
        $model = file_get_contents($root.'/app/Modules/Wms/Models/DocumentPhoto.php');
        $migration = file_get_contents($root.'/database/migrations/2026_09_17_120000_create_wms_document_photos.php');
        $installer = file_get_contents($root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $routes = file_get_contents($root.'/app/Modules/Wms/Routes/web.php');
        $partial = file_get_contents($root.'/app/Modules/Wms/Views/partials/document-photos.blade.php');

        self::assertStringContainsString("'photos' => ['required', 'array', 'min:1', 'max:5']", $request);
        self::assertStringContainsString("'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp'", $request);
        self::assertStringContainsString("'max:10240'", $request);
        self::assertStringContainsString("\$storage->store(\$file, 'wms/document-evidence'", $controller);
        self::assertStringContainsString("\$storage->delete(\$stored['disk'], \$stored['path'])", $controller);
        self::assertStringContainsString('$storage->inline($photo->disk, $photo->path', $controller);
        self::assertStringContainsString("'ISSUE', 'MATERIAL_ISSUE'", $controller);
        self::assertStringContainsString("'ISSUE_RETURN'", $controller);
        self::assertStringContainsString("'FINISHED_RECEIPT'", $controller);
        self::assertStringContainsString("'document_type', 'document_id', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by'", $model);
        self::assertStringContainsString("Schema::dropIfExists('wms_document_photos')", $migration);
        self::assertStringContainsString("'wms_document_photos' => ['document_type', 'document_id', 'disk', 'path'", $installer);
        self::assertStringContainsString("'wms_document_photos'", $installer);
        foreach (['issues.photos.store', 'issue-returns.photos.store', 'production.material-issues.photos.store', 'production.finished-receipts.photos.store'] as $route) {
            self::assertStringContainsString("name('$route')", $routes);
        }
        self::assertStringContainsString('<x-platform::file-uploader', $partial);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $partial);
        self::assertStringContainsString(':multiple="true"', $partial);
        self::assertStringContainsString(':max-files="5"', $partial);
        self::assertStringContainsString('max-file-size="10MB"', $partial);
        self::assertStringContainsString('data-error-for="photos.0"', $partial);

        foreach (['issues/show.blade.php', 'issue-returns/show.blade.php', 'production/material-issues/show.blade.php', 'production/finished-receipts/show.blade.php'] as $view) {
            self::assertStringContainsString("@include('Wms::partials.document-photos'", file_get_contents($root.'/app/Modules/Wms/Views/'.$view));
        }
    }
}
