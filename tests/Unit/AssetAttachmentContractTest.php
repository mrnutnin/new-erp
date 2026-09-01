<?php

namespace Tests\Unit;

use App\Modules\Asset\Controllers\AssetAttachmentController;
use App\Modules\Asset\Requests\StoreAssetAttachmentRequest;
use App\Modules\Platform\Services\FileStorageService;
use PHPUnit\Framework\TestCase;

final class AssetAttachmentContractTest extends TestCase
{
    public function test_attachment_storage_is_private_and_the_controller_uses_the_platform_contract(): void
    {
        $storage = file_get_contents((new \ReflectionClass(FileStorageService::class))->getFileName());
        $controller = file_get_contents((new \ReflectionClass(AssetAttachmentController::class))->getFileName());

        self::assertStringContainsString("config('filesystems.private_disk', 'local')", $storage);
        self::assertStringContainsString("if (\$disk === 'public')", $storage);
        self::assertStringContainsString('app()->environment()', $storage);
        self::assertStringContainsString('FileStorageService $storage', $controller);
        self::assertStringNotContainsString('Storage::disk', $controller);
        self::assertStringContainsString('function inline(', $storage);
    }

    public function test_attachment_upload_is_branch_scoped_and_validates_file_metadata(): void
    {
        $controller = file_get_contents((new \ReflectionClass(AssetAttachmentController::class))->getFileName());
        $request = new StoreAssetAttachmentRequest;

        self::assertStringContainsString("where('branch_id', \$request->attributes->get('selectedBranch')->id)", $controller);
        self::assertStringContainsString("'subject_type' => 'ASSET'", $controller);
        self::assertStringContainsString("\$attachment->file_type === 'PHOTO'", $controller);
        self::assertStringContainsString('asset.attachments.manage', file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Routes/web.php'));
        self::assertStringContainsString('assets.attachments.preview', file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Routes/web.php'));
        self::assertContains('mimes:pdf,jpg,jpeg,png,webp', $request->rules()['file']);
        self::assertContains('max:10240', $request->rules()['file']);
    }
}
