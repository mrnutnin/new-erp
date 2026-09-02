<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakeDownstreamCancellationContractTest extends TestCase
{
    public function test_completed_intake_can_be_revised_only_after_downstream_documents_are_terminal(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesIntakeController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/sales-intakes/show.blade.php');

        self::assertStringContainsString('private function canRevise', $controller);
        self::assertStringContainsString("['CANCELLED', 'REJECTED', 'VOID']", $controller);
        self::assertStringContainsString('abort_unless($this->canRevise($x), 403)', $controller);
        self::assertStringContainsString('@if($canRevise', $view);
    }
}
