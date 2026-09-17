<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmMyWorkContractTest extends TestCase
{
    #[Test]
    public function my_work_is_branch_user_scoped_and_server_paginated(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/MyWorkController.php'));
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));

        self::assertStringContainsString("where('branch_id'", $controller);
        self::assertStringContainsString("where('assigned_to', \$request->user()->id)", $controller);
        self::assertStringContainsString("where('owner_id', \$request->user()->id)", $controller);
        self::assertStringContainsString('paginate(12)', $controller);
        self::assertStringContainsString("Route::get('/my-work/data'", $routes);
        self::assertStringContainsString("permission:crm.opportunities.view", $routes);
    }

    #[Test]
    public function my_work_uses_ajax_list_cards_and_quick_actions(): void
    {
        $index = file_get_contents(base_path('app/Modules/Crm/Views/my-work/index.blade.php'));
        $cards = file_get_contents(base_path('app/Modules/Crm/Views/my-work/_cards.blade.php'));
        $form = file_get_contents(base_path('app/Modules/Crm/Views/opportunities/form.blade.php'));

        self::assertStringContainsString("$.getJSON(dataUrl", $index);
        self::assertStringContainsString('crm-work-filter', $index);
        self::assertStringContainsString("href=\"tel:", $cards);
        self::assertStringContainsString('#activity-form', $cards);
        self::assertStringContainsString('js-complete-work', $cards);
        self::assertStringContainsString('COMPLETED', $index);
        self::assertStringContainsString('เลือกสร้างแทนพนักงานขายคนอื่นได้', $form);
    }
}
