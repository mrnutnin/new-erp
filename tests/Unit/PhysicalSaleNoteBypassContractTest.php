<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleNoteBypassContractTest extends TestCase
{
    public function test_posted_sale_note_can_be_updated_without_touching_financial_fields(): void
    {
        $root = dirname(__DIR__, 2);
        $route = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $request = file_get_contents($root.'/app/Modules/Pos/Requests/UpdatePhysicalSaleNoteRequest.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/physical-sales/show.blade.php');

        self::assertStringContainsString('physical-sales/{physicalSale}/note', $route);
        self::assertStringContainsString('public function updateNote(', $controller);
        self::assertStringContainsString("'pos.physical-sale.note-updated'", $controller);
        self::assertStringContainsString("'description' => \$request->validated('description')", $controller);
        $updateNote = substr($controller, strpos($controller, 'public function updateNote('), strpos($controller, 'public function post(') - strpos($controller, 'public function updateNote('));
        self::assertStringNotContainsString("'total_amount' =>", $updateNote);
        self::assertStringContainsString("'description' => ['nullable', 'string', 'max:500']", $request);
        self::assertStringContainsString('แก้ไขเฉพาะหมายเหตุ', $view);
        self::assertStringContainsString("route('pos.physical-sales.note.update', \$sale)", $view);
    }
}
