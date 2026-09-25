<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class MintErpBrandingUiContractTest extends TestCase
{
    public function test_user_facing_views_use_minterp_branding(): void
    {
        $root = dirname(__DIR__, 2);
        $views = array_merge(
            $this->bladeFiles($root.'/app'),
            $this->bladeFiles($root.'/resources'),
        );

        $legacyViews = array_values(array_filter($views, function (string $view): bool {
            $contents = file_get_contents($view);

            return str_contains($contents, 'New ERP') || str_contains($contents, 'NEW ERP');
        }));

        self::assertSame([], $legacyViews, 'พบ Branding เดิมใน: '.implode(', ', $legacyViews));
        $layout = file_get_contents($root.'/resources/views/layouts/app.blade.php');
        self::assertStringContainsString('MintERP', $layout);
        self::assertSame(2, substr_count($layout, "asset('images/mint-erp-logo.png')"));
        self::assertStringNotContainsString("asset('images/mint-icon.png')", $layout);
        self::assertStringContainsString('favicon.svg', $layout);
        self::assertStringContainsString('favicon-32.png', $layout);
        self::assertStringContainsString('favicon.ico', $layout);
        self::assertStringContainsString('.app-sidebar-brand img', file_get_contents($root.'/public/css/app.css'));
        self::assertFileExists($root.'/public/favicon.svg');
        self::assertFileExists($root.'/public/favicon-32.png');
        self::assertGreaterThan(0, filesize($root.'/public/favicon.ico'));
        self::assertStringContainsString('data:image/png;base64,', file_get_contents($root.'/public/favicon.svg'));
        self::assertStringContainsString('.app-header-brand img', file_get_contents($root.'/public/css/app.css'));
        $login = file_get_contents($root.'/app/Modules/Platform/Views/auth/login.blade.php');
        self::assertStringContainsString('MintERP', $login);
        self::assertStringContainsString('images/mint-erp-logo.png', $login);
        self::assertStringContainsString('images/alexiasoft-logo.png', $login);
        self::assertStringContainsString("env('APP_NAME', 'MintERP')", file_get_contents($root.'/config/app.php'));
    }

    private function bladeFiles(string $directory): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
