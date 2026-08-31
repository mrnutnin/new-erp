<?php

namespace Tests\Unit;

use App\Modules\Finance\Requests\SaveDocumentSequenceRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class SaveDocumentSequenceRequestTest extends TestCase
{
    public function test_global_commercial_and_wms_document_types_are_allowed_without_a_warehouse_context(): void
    {
        $request = SaveDocumentSequenceRequest::create('/', 'POST');

        $this->assertContains(
            'in:RECEIPT,PAYMENT,SALES_INVOICE,SALES_CREDIT_NOTE,PURCHASE_INVOICE,PURCHASE_CREDIT_NOTE,PURCHASE_ORDER,INVENTORY_ADJUSTMENT,INVENTORY_ISSUE,INVENTORY_RETURN,SALES_RFQ,SALES_INTAKE,SALES_QUOTATION,SALES_ORDER,PURCHASE_REQUISITION,GOODS_RECEIPT,WMS_TRANSFER,STOCK_COUNT',
            $request->rules()['document_type'],
        );
    }

    public function test_format_requires_one_number_token_with_valid_width(): void
    {
        $request = SaveDocumentSequenceRequest::create('/', 'POST', ['number_format' => 'RC-{YYYY}']);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), []);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('number_format'));
    }

    public function test_format_allows_supported_tokens_only(): void
    {
        $request = SaveDocumentSequenceRequest::create('/', 'POST', ['number_format' => '{PREFIX}-{YYYY}-{MM}-{NUMBER:6}']);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), []);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertFalse($validator->fails());
    }

    public function test_format_allows_branch_and_short_year_tokens(): void
    {
        $request = SaveDocumentSequenceRequest::create('/', 'POST', ['prefix' => 'IV', 'number_format' => '{PREFIX}{BRANCH}{YYMM}{NUMBER:6}']);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), []);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertFalse($validator->fails());
    }

    public function test_generated_document_number_must_fit_database_column(): void
    {
        $request = SaveDocumentSequenceRequest::create('/', 'POST', [
            'prefix' => str_repeat('A', 20),
            'number_format' => '{PREFIX}-{YYYY}-{MM}-{NUMBER:12}',
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), []);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('number_format'));
    }
}
