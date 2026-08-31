<?php

namespace App\Modules\Finance\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePaymentTermRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:30', Rule::unique('finance_payment_terms')->ignore($this->route('paymentTerm'))], 'name' => ['required', 'string', 'max:255'], 'credit_days' => ['required', 'integer', 'min:0', 'max:3650'], 'due_rule' => ['required', 'in:DUE_ON_DATE,END_OF_MONTH'], 'is_active' => ['required', 'boolean']];
    }
}
