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
        self::assertStringContainsString('MintERP', file_get_contents($root.'/resources/views/layouts/app.blade.php'));
        self::assertStringContainsString('MintERP', file_get_contents($root.'/app/Modules/Platform/Views/auth/login.blade.php'));
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
