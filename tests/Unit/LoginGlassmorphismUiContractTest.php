<?php

namespace Tests\Unit;

use Tests\TestCase;

class LoginGlassmorphismUiContractTest extends TestCase
{
    public function test_login_uses_the_accessible_glass_layout(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/app/Modules/Platform/Views/auth/login.blade.php');
        $css = file_get_contents($root.'/public/css/app.css');

        self::assertStringContainsString('auth-intro', $view);
        self::assertStringContainsString('auth-input-wrap', $view);
        self::assertStringContainsString("asset('images/alexiasoft-logo.png')", $view);
        self::assertStringContainsString('auth-vendor-logo', $css);
        self::assertFileExists($root.'/public/images/alexiasoft-logo.png');
        self::assertStringContainsString('btn-app-primary', $view);
        self::assertStringContainsString('autocomplete="username"', $view);
        self::assertStringContainsString('autocomplete="current-password"', $view);
        self::assertStringContainsString('linear-gradient(135deg, rgba(255, 255, 255, .3)', $css);
        self::assertStringContainsString('backdrop-filter: blur(1.75rem)', $css);
        self::assertStringContainsString('@media (max-width: 991.98px)', $css);
    }
}
