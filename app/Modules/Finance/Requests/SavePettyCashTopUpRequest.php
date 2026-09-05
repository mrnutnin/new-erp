<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePettyCashTopUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'petty_cash_fund_id' => ['required', 'integer', Rule::exists('finance_petty_cash_funds', 'id')->where('is_active', true)],
            'source_bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->where('type', 'BANK')->where('is_active', true)],
            'document_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'], 'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
