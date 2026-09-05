<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveEmployeeAdvanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'employee_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)],
            'bank_account_id' => ['required', 'integer', Rule::exists('finance_bank_accounts', 'id')->where('is_active', true)],
            'document_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:document_date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999.99'],
            'purpose' => ['required', 'string', 'max:1000'],
        ];
    }
}
