<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePhysicalSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $taxCalculation = strtoupper((string) $this->input('tax_calculation', 'VAT_INCLUSIVE'));
        $tax = match ($taxCalculation) {
            'VAT_EXCLUSIVE' => ['tax_treatment' => 'VAT_OUT', 'prices_include_vat' => false],
            'NONE' => ['tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false],
            default => ['tax_treatment' => 'VAT_OUT', 'prices_include_vat' => true],
        };

        $this->merge([
            'description' => trim((string) $this->input('description')) ?: null,
            'due_date' => $this->filled('due_date') ? $this->input('due_date') : null,
            'tax_calculation' => $taxCalculation,
            ...$tax,
        ]);
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['HS', 'IV'])],
            'source_type' => ['required', Rule::in(['SALES_ORDER', 'PRODUCTION_RECEIPT'])],
            'source_id' => ['required', 'integer', 'min:1'],
            'fulfillment_warehouse_id' => ['required', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'tax_calculation' => ['required', Rule::in(['VAT_INCLUSIVE', 'VAT_EXCLUSIVE', 'NONE'])],
            'tax_treatment' => ['required', Rule::in(['VAT_OUT', 'NONE_VAT'])],
            'prices_include_vat' => ['required', 'boolean'],
            'tax_code_id' => [
                Rule::requiredIf(fn (): bool => $this->input('tax_treatment') === 'VAT_OUT'),
                Rule::prohibitedIf(fn (): bool => $this->input('tax_treatment') === 'NONE_VAT'),
                'nullable', 'integer',
                Rule::exists('tax_codes', 'id')->where(fn ($query) => $query->where('kind', 'VAT_OUT')->where('is_active', true)),
            ],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'withholding_tax_code_id' => ['nullable', 'integer', 'min:1', Rule::exists('tax_codes', 'id')->where(fn ($query) => $query->where('kind', 'WHT')->where('is_active', true))],
            'withholding_base' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'withholding_rate' => ['prohibited'],
            'withholding_amount' => ['prohibited'],
            'posting_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
