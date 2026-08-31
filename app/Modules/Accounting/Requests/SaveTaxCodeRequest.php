<?php

namespace App\Modules\Accounting\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveTaxCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => strtoupper(trim($this->string('code')->toString())), 'name' => trim($this->string('name')->toString()), 'rate' => $this->input('rate') ?: 0, 'is_active' => $this->boolean('is_active')]);
    }

    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:30', Rule::unique('tax_codes')->ignore($this->route('taxCode'))], 'name' => ['required', 'string', 'max:255'], 'kind' => ['required', 'in:VAT_IN,VAT_OUT,NONE_VAT,WHT'], 'rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,4'], 'is_active' => ['required', 'boolean']];
    }
}
