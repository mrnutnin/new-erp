<?php

namespace Tests\Unit;

use App\Modules\Accounting\Requests\SaveAccountRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class SaveAccountRequestTest extends TestCase
{
    public function test_control_account_requires_a_control_type(): void
    {
        $request = SaveAccountRequest::create('/', 'POST', [
            'account_type_id' => 1,
            'code' => '11111',
            'name' => 'บัญชีเงินฝากธนาคาร',
            'account_class' => 'CONTROL',
            'is_active' => true,
        ]);
        $rules = $request->rules();
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), [
            'account_class' => $rules['account_class'],
            'control_account_type' => $rules['control_account_type'],
        ]);

        $this->assertTrue($validator->errors()->has('control_account_type'));
    }
}
