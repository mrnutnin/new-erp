<?php

namespace Tests\Unit;

use App\Modules\Settings\Requests\UpdateCompanySettingRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class UpdateCompanySettingRequestTest extends TestCase
{
    public function test_negative_cost_method_is_required_when_negative_stock_is_enabled(): void
    {
        $request = UpdateCompanySettingRequest::create('/', 'PUT', [
            'company_name' => 'New ERP',
            'locale' => 'th',
            'timezone' => 'Asia/Bangkok',
            'base_currency' => 'THB',
            'date_format' => 'd/m/Y',
            'allow_negative_stock' => true,
            'manual_discount_approval_threshold' => 5,
            'effective_from' => now()->toDateString(),
            'change_reason' => 'Unit test',
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), $request->rules());

        $this->assertTrue($validator->errors()->has('negative_stock_cost_method'));
    }

    public function test_manual_discount_approval_threshold_must_be_a_percentage(): void
    {
        $request = UpdateCompanySettingRequest::create('/', 'PUT', [
            'company_name' => 'New ERP',
            'locale' => 'th',
            'timezone' => 'Asia/Bangkok',
            'base_currency' => 'THB',
            'date_format' => 'd/m/Y',
            'allow_negative_stock' => false,
            'manual_discount_approval_threshold' => 101,
            'tax_decimal_places' => 2,
            'effective_from' => now()->toDateString(),
            'change_reason' => 'Unit test',
        ]);
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), $request->rules());

        $this->assertTrue($validator->errors()->has('manual_discount_approval_threshold'));
    }
}
