<?php

namespace Tests\Unit;

use App\Modules\Platform\Support\DocumentFieldRegistry;
use App\Modules\Platform\Support\NormalizedDocumentPayloadContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DocumentFieldRegistryTest extends TestCase
{
    public function test_purchasing_fields_are_whitelisted(): void
    {
        self::assertTrue(DocumentFieldRegistry::allows('PURCHASE_ORDER', 'party.name'));
        self::assertFalse(DocumentFieldRegistry::allows('PURCHASE_ORDER', 'sql.raw'));
    }

    public function test_payload_is_normalized_for_renderer(): void
    {
        $payload = NormalizedDocumentPayloadContract::normalize('purchase_order', ['company' => [], 'document' => [], 'lines' => [], 'totals' => []]);
        self::assertSame('PURCHASE_ORDER', $payload['document_type']);
        self::assertSame([], $payload['signatures']);
    }

    public function test_payload_requires_core_sections(): void
    {
        $this->expectException(ValidationException::class);
        NormalizedDocumentPayloadContract::normalize('PURCHASE_ORDER', ['company' => []]);
    }
}
