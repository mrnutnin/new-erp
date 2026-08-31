<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $accountClass = strtoupper($this->string('account_class')->toString());

        $this->merge([
            'code' => strtoupper(trim($this->string('code')->toString())),
            'name' => trim($this->string('name')->toString()),
            'parent_id' => $this->filled('parent_id') ? $this->integer('parent_id') : null,
            'reporting_profile' => $this->filled('reporting_profile') ? $this->input('reporting_profile') : null,
            'account_class' => $accountClass,
            'control_account_type' => $accountClass === 'CONTROL' && $this->filled('control_account_type') ? $this->input('control_account_type') : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'account_type_id' => ['required', 'integer', 'exists:account_types,id'],
            'parent_id' => ['nullable', 'integer', 'different:account', Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'code' => ['required', 'string', 'max:50', Rule::unique('accounts')->ignore($this->route('account'))],
            'name' => ['required', 'string', 'max:255'],
            'account_class' => ['required', 'in:SUMMARY,SUBACCOUNT,CONTROL'],
            'reporting_profile' => ['nullable', 'in:PAE,NPAE'],
            'control_account_type' => ['nullable', 'required_if:account_class,CONTROL', 'in:AR,AP,INVENTORY,CASH,BANK,CREDIT_CARD,CHEQUE,FIXED_ASSET,INPUT_VAT,OUTPUT_VAT,WITHHOLDING_TAX,WIP'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
