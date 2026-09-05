<?php

namespace Tests\Unit;

use App\Modules\Platform\Support\DocumentTemplateVersionContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DocumentTemplateVersionContractTest extends TestCase
{
    public function test_draft_definition_can_be_published(): void
    {
        $result = DocumentTemplateVersionContract::publish(['template_id' => 2, 'version' => 1, 'status' => 'DRAFT', 'definition' => ['sections' => []]]);

        self::assertSame('PUBLISHED', $result['status']);
        self::assertTrue($result['published']);
    }

    public function test_published_version_cannot_be_published_again(): void
    {
        $this->expectException(ValidationException::class);

        DocumentTemplateVersionContract::publish(['template_id' => 2, 'version' => 1, 'status' => 'PUBLISHED', 'definition' => ['sections' => []]]);
    }
}
