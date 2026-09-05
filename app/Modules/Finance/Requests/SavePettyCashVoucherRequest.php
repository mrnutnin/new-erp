<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePettyCashVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'petty_cash_fund_id' => ['required', 'integer', Rule::exists('finance_petty_cash_funds', 'id')->where('is_active', true)],
            'document_date' => ['required', 'date'],
            'payee_type' => ['required', Rule::in(['EMPLOYEE', 'SUPPLIER', 'OTHER'])],
            'payee_user_id' => ['nullable', 'integer', 'required_if:payee_type,EMPLOYEE', Rule::exists('users', 'id')],
            'payee_party_id' => ['nullable', 'integer', 'required_if:payee_type,SUPPLIER', Rule::exists('parties', 'id')],
            'payee_name' => ['nullable', 'required_if:payee_type,OTHER', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.expense_category_id' => ['required', 'integer', Rule::exists('finance_other_categories', 'id')->where('kind', 'EXPENSE')->where('is_active', true)],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.receipt_reference' => ['nullable', 'string', 'max:100'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],
            'lines.*.tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->whereIn('kind', ['VAT_IN', 'NONE_VAT'])->where('is_active', true)],
            'lines.*.withholding_tax_code_id' => ['nullable', 'integer', Rule::exists('tax_codes', 'id')->where('kind', 'WHT')->where('is_active', true)],
        ];
    }
}
