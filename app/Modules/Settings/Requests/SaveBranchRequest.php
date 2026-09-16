<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim($this->string('code')->toString())),
            'name' => trim($this->string('name')->toString()),
            'tax_branch_code' => $this->filled('tax_branch_code') ? trim($this->string('tax_branch_code')->toString()) : null,
            'tax_address' => $this->filled('tax_address') ? trim($this->string('tax_address')->toString()) : null,
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50', Rule::unique('branches')->ignore($this->route('branch'))],
            'name' => ['required', 'string', 'max:255'],
            'tax_branch_code' => ['nullable', 'digits:5'],
            'tax_address' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
