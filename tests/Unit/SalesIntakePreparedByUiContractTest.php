<?php

namespace Tests\Unit;

use Tests\TestCase;

class SalesIntakePreparedByUiContractTest extends TestCase
{
    public function test_prepared_by_uses_select2_and_defaults_to_the_logged_in_user(): void
    {
        $root = dirname(__DIR__, 2);
        $form = file_get_contents($root.'/app/Modules/Pos/Views/sales-intakes/form.blade.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesIntakeController.php');

        self::assertStringContainsString('class="form-select js-prepared-by" name="prepared_by"', $form);
        self::assertStringContainsString("$('.js-prepared-by').select2", $form);
        self::assertStringContainsString("'prepared_by' => auth()->id()", $controller);
    }
}
