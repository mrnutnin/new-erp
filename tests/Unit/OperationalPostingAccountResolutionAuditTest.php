<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\PostingEvent;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class OperationalPostingAccountResolutionAuditTest extends TestCase
{
    public function test_legacy_mapping_resolution_is_not_used_by_live_operational_posting(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules';
        $exceptions = [
            'Purchasing/Services/ProcurementSourceBuilder.php',
        ];
        $legacyCallers = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = str_replace($root.'/', '', $file->getPathname());
            if (preg_match('/->(?:mappings|accountMappings)->resolve\(/', file_get_contents($file->getPathname()))) {
                $legacyCallers[] = $path;
            }
        }

        sort($legacyCallers);
        self::assertSame($exceptions, $legacyCallers);
        self::assertSame('LIVE', PostingEvent::contract('inventory.recost')['status']);
    }
}
