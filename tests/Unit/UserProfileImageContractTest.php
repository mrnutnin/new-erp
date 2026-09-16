<?php

namespace Tests\Unit;

use App\Models\CompanySetting;
use App\Models\User;
use App\Modules\Platform\Rules\SignatureDataUrl;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Platform\Services\UserMediaService;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Http\UploadedFile;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class UserProfileImageContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
    }

    public function test_profile_image_schema_is_migrated_and_checked_by_the_installer(): void
    {
        $migration = file_get_contents($this->root.'/database/migrations/2026_09_16_170000_add_profile_image_to_users.php');
        $installer = file_get_contents($this->root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $model = file_get_contents($this->root.'/app/Models/User.php');

        foreach (['profile_image_disk', 'profile_image_path'] as $column) {
            self::assertStringContainsString($column, $migration);
            self::assertStringContainsString($column, $installer);
            self::assertStringContainsString($column, $model);
        }
    }

    public function test_profile_and_user_forms_use_the_shared_private_image_uploader(): void
    {
        $profile = file_get_contents($this->root.'/app/Modules/Platform/Views/profile/edit.blade.php');
        $users = file_get_contents($this->root.'/app/Modules/Settings/Views/users/form.blade.php');
        $profileRequest = file_get_contents($this->root.'/app/Modules/Platform/Requests/UpdateProfileRequest.php');
        $userRequest = file_get_contents($this->root.'/app/Modules/Settings/Requests/SaveUserRequest.php');
        $platformRoutes = file_get_contents($this->root.'/app/Modules/Platform/Routes/web.php');
        $settingsRoutes = file_get_contents($this->root.'/app/Modules/Settings/Routes/web.php');

        foreach ([$profile, $users] as $view) {
            self::assertStringContainsString('enctype="multipart/form-data"', $view);
            self::assertStringContainsString('<x-platform::file-uploader', $view);
            self::assertStringContainsString('name="profile_image"', $view);
            self::assertStringContainsString('max-file-size="5MB"', $view);
            self::assertStringContainsString('class="row justify-content-center"', $view);
        }
        foreach ([$profileRequest, $userRequest] as $request) {
            self::assertStringContainsString("'profile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']", $request);
        }

        self::assertStringContainsString("name('profile.image')", $platformRoutes);
        self::assertStringContainsString("name('users.profile-image')", $settingsRoutes);
        self::assertStringContainsString('permission:settings.users.update', $settingsRoutes);
    }

    public function test_failed_profile_save_removes_the_new_s3_object(): void
    {
        $file = UploadedFile::fake()->image('profile.jpg');
        $stored = ['disk' => 's3', 'path' => 'user-profile-images/profile.jpg'];
        $storage = Mockery::mock(FileStorageService::class);
        $settings = Mockery::mock(GlobalSettings::class);
        $settings->shouldReceive('current')->once()->andReturn(new CompanySetting(['tax_id' => '0100000000000']));
        $storage->shouldReceive('store')->once()->with($file, 'user-profile-images', '0100000000000')->andReturn($stored);
        $storage->shouldReceive('delete')->once()->with('s3', 'user-profile-images/profile.jpg')->andReturnNull();

        $service = new UserMediaService($storage, $settings);

        $this->expectException(RuntimeException::class);
        $service->persist(new User, $file, false, null, null, false, function (): void {
            throw new RuntimeException('Database failed');
        });
    }

    public function test_signature_can_be_uploaded_or_drawn_and_is_stored_privately(): void
    {
        $migration = file_get_contents($this->root.'/database/migrations/2026_09_16_180000_add_signature_to_users.php');
        $installer = file_get_contents($this->root.'/app/Modules/Installer/Services/DatabasePreparationService.php');
        $model = file_get_contents($this->root.'/app/Models/User.php');
        $fields = file_get_contents($this->root.'/app/Modules/Platform/Views/components/user-signature-fields.blade.php');
        $pad = file_get_contents($this->root.'/app/Modules/Platform/Views/components/signature-pad.blade.php');
        $script = file_get_contents($this->root.'/public/js/platform-signature-pad.js');
        $profile = file_get_contents($this->root.'/app/Modules/Platform/Views/profile/edit.blade.php');
        $users = file_get_contents($this->root.'/app/Modules/Settings/Views/users/form.blade.php');
        $platformRoutes = file_get_contents($this->root.'/app/Modules/Platform/Routes/web.php');
        $settingsRoutes = file_get_contents($this->root.'/app/Modules/Settings/Routes/web.php');

        foreach (['signature_disk', 'signature_path'] as $column) {
            self::assertStringContainsString($column, $migration);
            self::assertStringContainsString($column, $installer);
            self::assertStringContainsString($column, $model);
        }
        self::assertStringContainsString('name="signature_image"', $fields);
        self::assertStringContainsString('<x-platform::signature-pad', $fields);
        self::assertStringContainsString('data-signature-canvas', $pad);
        self::assertStringContainsString("canvas.toDataURL('image/png')", $script);
        self::assertStringContainsString('<x-platform::user-signature-fields', $profile);
        self::assertStringContainsString('<x-platform::user-signature-fields', $users);
        self::assertStringContainsString("name('profile.signature')", $platformRoutes);
        self::assertStringContainsString("name('users.signature')", $settingsRoutes);
    }

    public function test_drawn_signature_accepts_only_a_bounded_png_data_url(): void
    {
        $rule = new SignatureDataUrl;
        $validPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        $failures = [];

        $rule->validate('signature_data', $validPng, function (string $message) use (&$failures): void {
            $failures[] = $message;
        });
        self::assertSame([], $failures);

        $rule->validate('signature_data', 'data:image/jpeg;base64,invalid', function (string $message) use (&$failures): void {
            $failures[] = $message;
        });
        self::assertNotEmpty($failures);
    }

    public function test_personal_details_and_password_use_separate_forms_and_requests(): void
    {
        $view = file_get_contents($this->root.'/app/Modules/Platform/Views/profile/edit.blade.php');
        $routes = file_get_contents($this->root.'/app/Modules/Platform/Routes/web.php');
        $profileRequest = file_get_contents($this->root.'/app/Modules/Platform/Requests/UpdateProfileRequest.php');
        $passwordRequest = file_get_contents($this->root.'/app/Modules/Platform/Requests/UpdatePasswordRequest.php');

        self::assertStringContainsString('id="profile-form"', $view);
        self::assertStringContainsString('id="profile-password-form"', $view);
        self::assertStringContainsString("route('profile.password.update')", $view);
        self::assertStringContainsString("name('profile.password.update')", $routes);
        self::assertStringNotContainsString('current_password', $profileRequest);
        self::assertStringContainsString("'current_password' => ['required', 'current_password']", $passwordRequest);
        self::assertStringContainsString("'password' => ['required', 'string', 'min:8', 'confirmed']", $passwordRequest);
    }

    public function test_profile_displays_account_identity_and_primary_branch(): void
    {
        $controller = file_get_contents($this->root.'/app/Modules/Platform/Controllers/ProfileController.php');
        $view = file_get_contents($this->root.'/app/Modules/Platform/Views/profile/edit.blade.php');

        self::assertStringContainsString("'primaryBranch:id,code,name'", $controller);
        foreach (['ข้อมูลบัญชีผู้ใช้งาน', 'ชื่อผู้ใช้งาน', 'รหัสพนักงาน', 'Username', 'สาขาที่สังกัด', 'สถานะบัญชี'] as $label) {
            self::assertStringContainsString($label, $view);
        }
        self::assertStringContainsString('$user->employee_code', $view);
        self::assertStringContainsString('$user->username', $view);
        self::assertStringContainsString('$user->primaryBranch', $view);
    }

    public function test_uploaded_profile_image_replaces_header_and_sidebar_icons_lazily(): void
    {
        $layout = file_get_contents($this->root.'/resources/views/layouts/app.blade.php');
        $styles = file_get_contents($this->root.'/public/css/app.css');

        self::assertGreaterThanOrEqual(2, substr_count($layout, "route('profile.image')"));
        self::assertGreaterThanOrEqual(2, substr_count($layout, 'loading="lazy"'));
        self::assertStringContainsString('auth()->user()->profile_image_path', $layout);
        self::assertStringContainsString('app-user-avatar', $layout);
        self::assertStringContainsString('.app-user-avatar img', $styles);
        self::assertStringContainsString('object-fit: cover', $styles);
    }
}
