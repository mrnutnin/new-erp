<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Requests\SaveItemRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class WmsItemImageContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_item_image_schema_is_migrated_and_checked_by_the_installer(): void
    {
        $migration = file_get_contents($this->root.'/database/migrations/2026_09_16_160000_add_images_to_wms_items.php');
        $installer = file_get_contents($this->root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $model = file_get_contents($this->root.'/app/Modules/Wms/Models/Item.php');

        foreach (['cover_image_disk', 'cover_image_path', 'additional_images'] as $column) {
            self::assertStringContainsString($column, $migration);
            self::assertStringContainsString($column, $installer);
            self::assertStringContainsString($column, $model);
        }
        self::assertStringContainsString("'additional_images' => 'array'", $model);
    }

    public function test_item_form_uses_the_shared_uploader_for_one_cover_and_five_gallery_images(): void
    {
        $view = file_get_contents($this->root.'/app/Modules/Wms/Views/items/form.blade.php');
        $component = file_get_contents($this->root.'/app/Modules/Platform/Views/components/file-uploader.blade.php');
        $script = file_get_contents($this->root.'/public/js/platform-file-uploader.js');

        self::assertStringContainsString('enctype="multipart/form-data"', $view);
        self::assertStringContainsString('name="cover_image"', $view);
        self::assertStringContainsString('name="additional_images"', $view);
        self::assertStringContainsString(':max-files="1"', $view);
        self::assertStringContainsString(':multiple="false"', $view);
        self::assertStringContainsString(':max-files="5"', $view);
        self::assertStringContainsString('ข้อมูลสินค้า', $view);
        self::assertStringContainsString('การกำหนดบัญชี', $view);
        self::assertStringContainsString('remove_additional_images[]', $view);
        self::assertStringContainsString("'maxFiles' => null", $component);
        self::assertStringContainsString('maxFiles: input.dataset.maxFiles', $script);
    }

    public function test_item_images_are_validated_stored_privately_and_served_through_authorized_routes(): void
    {
        $request = file_get_contents($this->root.'/app/Modules/Wms/Requests/SaveItemRequest.php');
        $controller = file_get_contents($this->root.'/app/Modules/Wms/Controllers/ItemController.php');
        $routes = file_get_contents($this->root.'/app/Modules/Wms/Routes/web.php');

        self::assertStringContainsString("'cover_image' => ['nullable', 'image'", $request);
        self::assertStringContainsString("'additional_images' => ['nullable', 'array', 'max:5']", $request);
        self::assertStringContainsString('ภาพเพิ่มเติมรวมทั้งหมดต้องไม่เกิน 5 ภาพ', $request);
        self::assertStringContainsString("'wms-item-images'", $controller);
        self::assertStringContainsString('FileStorageService $storage', $controller);
        self::assertStringContainsString("name('items.cover-image')", $routes);
        self::assertStringContainsString("name('items.additional-image')", $routes);
        self::assertStringContainsString('permission:wms.items.update', $routes);
    }

    public function test_gallery_validation_counts_existing_and_new_images_together(): void
    {
        $request = SaveItemRequest::create('/', 'PUT', [], [], [
            'additional_images' => [
                UploadedFile::fake()->create('new-1.jpg', 10, 'image/jpeg'),
                UploadedFile::fake()->create('new-2.jpg', 10, 'image/jpeg'),
            ],
        ]);
        $item = new Item(['additional_images' => array_fill(0, 4, ['disk' => 's3', 'path' => 'image.jpg'])]);
        $request->setRouteResolver(fn () => new class($item)
        {
            public function __construct(private readonly Item $item) {}

            public function parameter(string $key): ?Item
            {
                return $key === 'item' ? $this->item : null;
            }
        });
        $validator = (new Factory(new Translator(new ArrayLoader, 'th')))->make([], []);
        $request->withValidator($validator);

        self::assertTrue($validator->errors()->has('additional_images'));
        self::assertSame('ภาพเพิ่มเติมรวมทั้งหมดต้องไม่เกิน 5 ภาพ', $validator->errors()->first('additional_images'));
    }
}
