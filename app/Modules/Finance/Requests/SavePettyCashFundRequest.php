<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePettyCashFundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')],
            'name' => ['required', 'string', 'max:150'],
            'bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->where('type', 'CASH')->where('is_active', true)],
            'custodian_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'fund_limit' => ['required', 'numeric', 'gte:0', 'max:99999999999999.99'],
            'is_active' => ['required', 'boolean'],
        ];
    }

}
