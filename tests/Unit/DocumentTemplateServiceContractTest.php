<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\DocumentTemplateService;
use Tests\TestCase;

final class DocumentTemplateServiceContractTest extends TestCase
{
    public function test_service_keeps_template_scope_and_atomic_publish_lifecycle(): void
    {
        $source = file_get_contents(base_path('app/Modules/Platform/Services/DocumentTemplateService.php'));

        self::assertStringContainsString("where('company_setting_id', \$company->id)", $source);
        self::assertStringContainsString('DB::transaction', $source);
        self::assertStringContainsString("'status' => 'RETIRED'", $source);
        self::assertStringContainsString('DocumentTemplateVersionContract::publish', $source);
        self::assertTrue(class_exists(DocumentTemplateService::class));
    }
}
