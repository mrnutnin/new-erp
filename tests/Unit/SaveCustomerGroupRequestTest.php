<?php

namespace Tests\Unit;

use App\Modules\Pos\Requests\SaveCustomerGroupRequest;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class SaveCustomerGroupRequestTest extends TestCase
{
    public function test_group_requires_a_safe_code_and_name(): void
    {
        $request = SaveCustomerGroupRequest::create('/', 'POST', ['code' => 'กลุ่ม ลูกค้า', 'name' => '']);
        $rules = $request->rules();
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => $rules['name'], 'is_active' => $rules['is_active'],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('code'));
        $this->assertTrue($validator->errors()->has('name'));
    }

    public function test_group_accepts_active_flag_and_safe_code(): void
    {
        $request = SaveCustomerGroupRequest::create('/', 'POST', ['code' => 'RETAIL-01', 'name' => 'ร้านค้าปลีก', 'is_active' => 1]);
        $rules = $request->rules();
        $validator = (new Factory(new Translator(new ArrayLoader, 'en')))->make($request->all(), [
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'], 'name' => $rules['name'], 'is_active' => $rules['is_active'],
        ]);

        $this->assertFalse($validator->fails());
    }
}
