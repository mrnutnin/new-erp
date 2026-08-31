<?php

namespace App\Modules\Settings\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanySettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $allowNegativeStock = $this->filled('allow_negative_stock') ? $this->boolean('allow_negative_stock') : null;

        $this->merge([
            'tax_id' => $this->filled('tax_id') ? trim($this->string('tax_id')->toString()) : null,
            'base_currency' => strtoupper($this->string('base_currency')->toString()),
            'allow_negative_stock' => $allowNegativeStock,
            'negative_stock_cost_method' => $allowNegativeStock === true ? $this->input('negative_stock_cost_method') : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:2000'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'tax_id' => ['nullable', 'digits:13'],
            'locale' => ['required', 'in:th,en'],
            'timezone' => ['required', 'timezone:all'],
            'base_currency' => ['required', 'alpha', 'size:3'],
            'date_format' => ['required', 'in:d/m/Y,Y-m-d'],
            'business_profile' => ['nullable', 'in:TRADING,MANUFACTURING'],
            'production_enabled' => ['nullable', 'boolean'],
            'accounting_profile' => ['nullable', 'in:PAE,NPAE'],
            'inventory_costing_method' => ['nullable', 'in:AVG,FIFO'],
            'allow_negative_stock' => ['required', 'boolean'],
            'negative_stock_cost_method' => ['nullable', Rule::requiredIf(fn () => $this->boolean('allow_negative_stock')), 'in:CURRENT_AVERAGE,LAST_KNOWN,STANDARD'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'default_vat_rate' => ['nullable', 'numeric', 'between:0,100'],
            'default_withholding_tax_rate' => ['nullable', 'numeric', 'between:0,100'],
            'tax_decimal_places' => ['required', 'integer', 'between:0,4'],
            'manual_discount_approval_threshold' => ['required', 'numeric', 'between:0,100'],
            'document_sequence_reset' => ['nullable', 'in:NEVER,YEARLY,MONTHLY'],
            'posting_sla_minutes' => ['nullable', 'integer', 'between:1,10080'],
            'recost_sla_minutes' => ['nullable', 'integer', 'between:1,10080'],
            'audit_retention_days' => ['nullable', 'integer', 'between:1,36500'],
            'file_retention_days' => ['nullable', 'integer', 'between:1,36500'],
            'effective_from' => ['required', 'date', 'before_or_equal:today'],
            'change_reason' => ['required', 'string', 'max:500'],
        ];
    }
}
