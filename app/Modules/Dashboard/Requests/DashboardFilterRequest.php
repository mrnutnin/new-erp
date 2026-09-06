<?php

namespace App\Modules\Dashboard\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DashboardFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'branch_id' => $this->input('branch_id', 'all'),
            'business_unit_id' => $this->input('business_unit_id', 'all'),
        ]);
    }

    public function rules(): array
    {
        return [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from', 'before_or_equal:today'],
            'company_id' => ['nullable', 'integer', 'exists:company_settings,id'],
            'branch_id' => ['nullable', 'regex:/^(all|[0-9]+)$/'],
            'business_unit_id' => ['nullable', 'string', 'max:50'],
        ];
    }
}
