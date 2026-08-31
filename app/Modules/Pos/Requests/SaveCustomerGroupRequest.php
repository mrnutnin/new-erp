<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // The current ERP has one active CompanySetting row (id 1). The
        // controller still applies the runtime company scope for reads/writes.
        $companySettingId = 1;
        $group = $this->route('customerGroup');

        return [
            'code' => [
                'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('pos_customer_groups', 'code')
                    ->where(fn ($query) => $query->where('company_setting_id', $companySettingId))
                    ->ignore($group),
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['code' => 'รหัสกลุ่มลูกค้า', 'name' => 'ชื่อกลุ่มลูกค้า', 'is_active' => 'สถานะ'];
    }
}
