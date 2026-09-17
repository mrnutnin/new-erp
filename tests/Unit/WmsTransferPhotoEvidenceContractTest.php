<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class WmsTransferPhotoEvidenceContractTest extends TestCase
{
    public function test_transfer_photos_follow_private_storage_and_comparison_contract(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Wms/Controllers/TransferPhotoController.php');
        $request = file_get_contents($root.'/app/Modules/Wms/Requests/StoreTransferPhotosRequest.php');
        $model = file_get_contents($root.'/app/Modules/Wms/Models/TransferPhoto.php');
        $migration = file_get_contents($root.'/database/migrations/2026_09_17_110000_create_wms_transfer_photos.php');
        $installer = file_get_contents($root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $routes = file_get_contents($root.'/app/Modules/Wms/Routes/web.php');
        $partial = file_get_contents($root.'/app/Modules/Wms/Views/transfers/_photos.blade.php');
        $show = file_get_contents($root.'/app/Modules/Wms/Views/transfers/show.blade.php');
        $receive = file_get_contents($root.'/app/Modules/Wms/Views/transfers/receive.blade.php');

        self::assertStringContainsString("Rule::in(['OUTGOING', 'INCOMING'])", $request);
        self::assertStringContainsString("'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp'", $request);
        self::assertStringContainsString("'max:10240'", $request);
        self::assertStringContainsString("\$storage->store(\$file, 'wms/transfer-evidence'", $controller);
        self::assertStringContainsString("\$storage->delete(\$stored['disk'], \$stored['path'])", $controller);
        self::assertStringContainsString("\$storage->inline(\$photo->disk, \$photo->path", $controller);
        self::assertStringContainsString("hasPermission('wms.transfers.dispatch')", $controller);
        self::assertStringContainsString("hasPermission('wms.transfers.complete')", $controller);
        self::assertStringContainsString("'transfer_id', 'stage', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by'", $model);
        self::assertStringContainsString("Schema::dropIfExists('wms_transfer_photos')", $migration);
        self::assertStringContainsString("'wms_transfer_photos' => ['transfer_id', 'stage', 'disk', 'path'", $installer);
        self::assertStringContainsString("permission:wms.transfers.view", $routes);
        self::assertStringContainsString('<x-platform::file-uploader', $partial);
        self::assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $partial);
        self::assertStringContainsString(':multiple="true"', $partial);
        self::assertStringContainsString(':max-files="5"', $partial);
        self::assertStringContainsString('max-file-size="10MB"', $partial);
        self::assertStringContainsString('data-error-for="photos.0"', $partial);
        self::assertStringContainsString("@include('Wms::transfers._photos')", $show);
        self::assertStringContainsString("@include('Wms::transfers._photos')", $receive);
    }
}
