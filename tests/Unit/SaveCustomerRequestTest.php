<?php

namespace Tests\Unit;

use App\Modules\Pos\Requests\SaveCustomerRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class SaveCustomerRequestTest extends TestCase
{
    public function test_customer_type_and_thai_tax_fields_are_validated(): void
    {
        $request = SaveCustomerRequest::create('/', 'POST', [
            'type' => 'UNKNOWN',
            'tax_id' => '123',
            'branch_code' => '12',
        ]);
        $rules = $request->rules();
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), [
            'type' => $rules['type'],
            'tax_id' => $rules['tax_id'],
            'branch_code' => $rules['branch_code'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('type'));
        $this->assertTrue($validator->errors()->has('tax_id'));
        $this->assertTrue($validator->errors()->has('branch_code'));
    }

    public function test_customer_foundation_accepts_many_billing_and_shipping_addresses(): void
    {
        $request = SaveCustomerRequest::create('/', 'POST', [
            'addresses' => [
                ['address_type' => 'BILLING', 'address_line' => 'สำนักงานใหญ่'],
                ['address_type' => 'BILLING', 'address_line' => 'สาขาเชียงใหม่'],
                ['address_type' => 'SHIPPING', 'address_line' => 'คลังสินค้า'],
            ],
        ]);
        $rules = $request->rules();
        $addressRules = array_filter($rules, fn ($key) => str_starts_with($key, 'addresses'), ARRAY_FILTER_USE_KEY);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), $addressRules);

        $this->assertArrayHasKey('customer_group_id', $rules);
        $this->assertArrayHasKey('addresses.*.id', $rules);
        $this->assertArrayHasKey('addresses.*.address_type', $rules);
        $this->assertArrayHasKey('addresses.*.address_line', $rules);
        $this->assertFalse($validator->fails());
    }
}
