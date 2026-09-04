<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Requests\SaveSupplierRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class SaveSupplierRequestTest extends TestCase
{
    public function test_contact_and_credit_fields_reject_invalid_values(): void
    {
        $request = SaveSupplierRequest::create('/', 'POST', [
            'type' => 'OTHER',
            'email' => 'not-an-email',
            'credit_limit' => '-0.01',
            'is_active' => '1',
        ]);
        $rules = $request->rules();
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), [
            'type' => $rules['type'],
            'email' => $rules['email'],
            'credit_limit' => $rules['credit_limit'],
            'is_active' => $rules['is_active'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('type'));
        $this->assertTrue($validator->errors()->has('email'));
        $this->assertTrue($validator->errors()->has('credit_limit'));
    }
}
