<?php

namespace Tests\Unit;

use App\Modules\Wms\Requests\PostPurchaseDocumentRequest;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PostPurchaseDocumentRequestTest extends TestCase
{
    public function test_posting_date_cannot_be_before_document_date(): void
    {
        $request = PostPurchaseDocumentRequest::create('/', 'POST');
        $document = (object) ['document_date' => new CarbonImmutable('2026-08-20')];
        $request->setRouteResolver(fn () => new class($document)
        {
            public function __construct(private readonly object $document) {}

            public function parameter(string $key): ?object
            {
                return $key === 'purchaseDocument' ? $this->document : null;
            }
        });

        $this->assertContains('after_or_equal:2026-08-20', $request->rules()['posting_date']);
    }
}
