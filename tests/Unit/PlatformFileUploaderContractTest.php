<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PlatformFileUploaderContractTest extends TestCase
{
    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    public function test_platform_registers_a_shared_anonymous_file_uploader_component(): void
    {
        $provider = file_get_contents($this->projectPath('app/Modules/Platform/Providers/PlatformServiceProvider.php'));
        $component = file_get_contents($this->projectPath('app/Modules/Platform/Views/components/file-uploader.blade.php'));

        $this->assertStringContainsString("Blade::anonymousComponentPath(__DIR__.'/../Views/components', 'platform')", $provider);
        $this->assertStringContainsString('data-platform-file-input', $component);
        $this->assertStringContainsString('filepond@4.32.12', $component);
        $this->assertStringContainsString('filepond-plugin-image-preview@4.6.12', $component);
        $this->assertStringContainsString('filepond-plugin-file-validate-type@1.2.9', $component);
        $this->assertStringContainsString('filepond-plugin-file-validate-size@2.2.8', $component);
        $this->assertStringContainsString('@once', $component);
    }

    public function test_file_uploader_keeps_native_form_submission_and_has_a_native_fallback(): void
    {
        $script = file_get_contents($this->projectPath('public/js/platform-file-uploader.js'));

        $this->assertStringContainsString('storeAsFile: true', $script);
        $this->assertStringContainsString("typeof DataTransfer !== 'undefined'", $script);
        $this->assertStringContainsString('if (!window.FilePond || !supportsNativeFileStorage())', $script);
        $this->assertStringNotContainsString('server:', $script);
        $this->assertLessThan(
            strpos($script, 'window.FilePond.create(input'),
            strpos($script, "input.closest('[data-platform-file-uploader]')")
        );
    }

    public function test_existing_image_uses_the_shared_accessible_preview_card(): void
    {
        $component = file_get_contents($this->projectPath('app/Modules/Platform/Views/components/file-uploader.blade.php'));
        $styles = file_get_contents($this->projectPath('public/css/app.css'));

        $this->assertStringContainsString('platform-file-uploader__current-help', $component);
        $this->assertStringContainsString('ภาพปัจจุบัน', $component);
        $this->assertStringContainsString('loading="lazy"', $component);
        $this->assertStringContainsString('min-height: 12rem', $styles);
        $this->assertStringContainsString('object-position: center', $styles);
    }

    public function test_company_logo_uses_the_platform_component_and_keeps_server_validation(): void
    {
        $view = file_get_contents($this->projectPath('app/Modules/Settings/Views/company/edit.blade.php'));
        $request = file_get_contents($this->projectPath('app/Modules/Settings/Requests/UpdateCompanySettingRequest.php'));

        $this->assertStringContainsString('<x-platform::file-uploader', $view);
        $this->assertStringContainsString('name="logo"', $view);
        $this->assertStringContainsString('max-file-size="2MB"', $view);
        $this->assertStringContainsString('class="row justify-content-center"', $view);
        $this->assertStringContainsString("'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048']", $request);
    }
}
