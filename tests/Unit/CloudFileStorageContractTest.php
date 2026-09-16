<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Settings\Support\SettingRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CloudFileStorageContractTest extends TestCase
{
    public function test_s3_is_the_default_for_persistent_uploads(): void
    {
        self::assertSame('s3', config('filesystems.default'));
        self::assertSame('s3', config('filesystems.private_disk'));
        self::assertTrue((bool) config('filesystems.disks.s3.throw'));
        self::assertArrayHasKey('league/flysystem-aws-s3-v3', json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR)['require']);
    }

    public function test_file_storage_service_uploads_and_deletes_through_the_configured_s3_disk(): void
    {
        Storage::fake('s3');
        config()->set('filesystems.private_disk', 's3');
        $file = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf');

        $stored = app(FileStorageService::class)->store($file, 'test', '0123456789012');

        self::assertSame('s3', $stored['disk']);
        Storage::disk('s3')->assertExists($stored['path']);
        app(FileStorageService::class)->delete($stored['disk'], $stored['path']);
        Storage::disk('s3')->assertMissing($stored['path']);
    }

    public function test_company_logo_records_its_disk_and_is_served_without_a_public_bucket(): void
    {
        self::assertContains('logo_disk', (new CompanySetting)->getFillable());

        $controller = file_get_contents(base_path('app/Modules/Settings/Controllers/CompanySettingController.php'));
        $view = file_get_contents(base_path('app/Modules/Settings/Views/company/edit.blade.php'));
        $routes = file_get_contents(base_path('app/Modules/Settings/Routes/web.php'));
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_150000_add_logo_disk_to_company_settings.php'));

        self::assertStringContainsString("\$values['logo_disk']", $controller);
        self::assertStringContainsString("'company-logo'", $controller);
        self::assertStringContainsString('settings.company.logo', $view);
        self::assertStringContainsString("name('company.logo')", $routes);
        self::assertStringContainsString("'logo_disk'", $migration);
    }

    public function test_pdf_logo_is_read_from_its_recorded_disk_as_a_data_uri(): void
    {
        Storage::fake('s3');
        Storage::disk('s3')->put('company/logo.png', 'logo-bytes');
        $setting = new CompanySetting(['logo_disk' => 's3', 'logo_path' => 'company/logo.png']);
        $settings = new class(new SettingRegistry, $setting) extends GlobalSettings
        {
            public function __construct(SettingRegistry $registry, private readonly CompanySetting $setting)
            {
                parent::__construct($registry);
            }

            public function current(): CompanySetting
            {
                return $this->setting;
            }
        };

        self::assertSame('data:image/png;base64,'.base64_encode('logo-bytes'), $settings->logoDataUri());
    }

    public function test_all_pdf_logo_consumers_use_storage_agnostic_data_uris(): void
    {
        $directories = [
            base_path('app/Modules/Accounting/Controllers'),
            base_path('app/Modules/Pos/Controllers'),
            base_path('app/Modules/Purchasing/Controllers'),
        ];
        $sources = collect($directories)
            ->flatMap(fn (string $directory) => glob($directory.'/*.php') ?: [])
            ->map(fn (string $file) => file_get_contents($file))
            ->implode("\n");

        self::assertStringNotContainsString("Storage::disk('public')->path", $sources);
        self::assertStringContainsString('logoDataUri()', $sources);
    }

    public function test_installer_validates_s3_and_the_logo_disk_schema(): void
    {
        $setup = file_get_contents(base_path('app/Modules/Installer/Controllers/SetupController.php'));
        $database = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));
        $migration = base_path('database/migrations/2026_09_16_150000_add_logo_disk_to_company_settings.php');

        self::assertFileExists($migration);
        self::assertStringContainsString("Artisan::call('migrate', ['--force' => true])", $database);
        self::assertStringContainsString('AWS S3 upload storage', $setup);
        self::assertStringContainsString('objectStorageCheck()', $setup);
        self::assertStringContainsString("'company_settings' => ['logo_disk']", $database);
    }
}
