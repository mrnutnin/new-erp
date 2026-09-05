<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SavePettyCashClearingRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'petty_cash_fund_id' => ['required', 'integer', Rule::exists('finance_petty_cash_funds', 'id')->where('is_active', true)],
            'clearing_date' => ['required', 'date'],
            'actual_amount' => ['required', 'numeric', 'min:0', 'max:99999999999999.99'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
