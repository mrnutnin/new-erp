<?php

namespace Tests\Unit;

use App\Modules\Finance\Requests\SaveSettlementRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class SaveSettlementRequestTest extends TestCase
{
    public function test_net_amount_must_equal_gross_less_withholding(): void
    {
        $request = SaveSettlementRequest::create('/', 'POST', [
            'gross_amount' => '1070.00',
            'tax_amount' => '70.00',
            'withholding_amount' => '30.00',
            'net_amount' => '1070.00',
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), []);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('net_amount'));
    }

    public function test_allocations_require_distinct_open_items_and_matching_party_type(): void
    {
        $request = SaveSettlementRequest::create('/', 'POST', [
            'document_type' => 'RECEIPT',
            'party_type' => 'SUPPLIER',
            'gross_amount' => '100.00',
            'tax_amount' => '0.00',
            'withholding_amount' => '0.00',
            'net_amount' => '100.00',
            'allocations' => [
                ['open_item_id' => 10, 'amount' => '40.00'],
                ['open_item_id' => 10, 'amount' => '60.00'],
            ],
        ]);
        $request->attributes->set('selectedWarehouse', (object) ['id' => 1]);
        $rules = $request->rules();
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), [
            'allocations' => $rules['allocations'],
            'allocations.*.open_item_id' => $rules['allocations.*.open_item_id'],
            'allocations.*.amount' => $rules['allocations.*.amount'],
        ]);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('allocations.1.open_item_id'));
        $this->assertTrue($validator->errors()->has('party_type'));
    }

    public function test_allocations_cannot_exceed_receipt_amount(): void
    {
        $request = SaveSettlementRequest::create('/', 'POST', [
            'gross_amount' => '100.00',
            'tax_amount' => '0.00',
            'withholding_amount' => '0.00',
            'net_amount' => '100.00',
            'allocations' => [
                ['open_item_id' => 10, 'amount' => '60.00'],
                ['open_item_id' => 11, 'amount' => '50.00'],
            ],
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), []);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('allocations'));
    }

    public function test_tenders_can_match_the_net_amount(): void
    {
        $request = SaveSettlementRequest::create('/', 'POST', [
            'gross_amount' => '1900.00',
            'tax_amount' => '0.00',
            'withholding_amount' => '0.00',
            'net_amount' => '1900.00',
            'tenders' => [['bank_account_id' => 2, 'amount' => '1900.00']],
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), []);
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        $this->assertFalse($validator->fails());
    }
}
