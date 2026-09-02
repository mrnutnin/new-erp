<?php

namespace App\Modules\Pos\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSalesIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $taxCalculation = $this->input('tax_calculation', 'VAT_INCLUSIVE');
        [$taxTreatment, $pricesIncludeVat] = match ($taxCalculation) {
            'VAT_EXCLUSIVE' => ['VAT_OUT', false],
            'NONE' => ['NONE_VAT', false],
            default => ['VAT_OUT', true],
        };
        $this->merge([
            'description' => trim((string) $this->input('description')) ?: null,
            'source' => trim((string) $this->input('source')) ?: null,
            'order_method' => trim((string) $this->input('order_method')) ?: null,
            'delivery_method' => trim((string) $this->input('delivery_method')) ?: null,
            'billing_address' => trim((string) $this->input('billing_address')) ?: null,
            'shipping_address' => trim((string) $this->input('shipping_address')) ?: null,
            'document_promotion_id' => $this->filled('document_promotion_id') ? (int) $this->input('document_promotion_id') : null,
            'tax_calculation' => $taxCalculation,
            'tax_treatment' => $taxTreatment,
            'prices_include_vat' => $pricesIncludeVat,
            'lines' => collect($this->input('lines', []))->map(fn ($line) => is_array($line)
                ? array_merge($line, ['description' => trim((string) ($line['description'] ?? '')) ?: null])
                : $line)->all(),
        ]);
    }

    public function rules(): array
    {
        $decimal = WmsDecimal::rule();

        return [
            'party_id' => ['required', 'integer', 'min:1'],
            'prepared_by' => ['nullable', 'integer', 'min:1'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'appointment_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'source' => ['nullable', 'string', 'max:100'],
            'order_method' => ['nullable', 'string', 'max:30'],
            'delivery_method' => ['nullable', 'string', 'max:30'],
            'description' => ['nullable', 'string', 'max:500'],
            'tax_calculation' => ['required', 'in:VAT_INCLUSIVE,VAT_EXCLUSIVE,NONE'],
            'tax_treatment' => ['required', 'in:NONE_VAT,VAT_OUT'],
            'prices_include_vat' => ['sometimes', 'boolean'],
            'tax_code_id' => ['nullable', 'integer', Rule::requiredIf(fn (): bool => $this->input('tax_treatment') === 'VAT_OUT'), Rule::prohibitedIf(fn (): bool => $this->input('tax_treatment') === 'NONE_VAT')],
            'billing_address' => ['nullable', 'string', 'max:1000'],
            'shipping_address' => ['nullable', 'string', 'max:1000'],
            'document_promotion_id' => ['nullable', 'integer', 'min:1'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_number' => ['nullable', 'integer', 'min:1', 'distinct'],
            'lines.*.item_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.uom_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.quantity' => array_merge(['required'], $decimal, ['gt:0']),
            'lines.*.requested_unit_price' => array_merge(['nullable'], $decimal, ['gte:0']),
            'lines.*.discount_amount' => array_merge(['nullable'], $decimal, ['gte:0']),
            'lines.*.promotion_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
