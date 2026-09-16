<?php

namespace App\Modules\Purchasing\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $taxCalculation = $this->filled('tax_calculation')
            ? strtoupper(trim((string) $this->input('tax_calculation')))
            : (strtoupper((string) $this->input('tax_treatment')) === 'VAT_IN'
                ? ($this->boolean('prices_include_vat') ? 'VAT_INCLUSIVE' : 'VAT_EXCLUSIVE')
                : 'NONE');
        $tax = match ($taxCalculation) {
            'VAT_INCLUSIVE' => ['tax_treatment' => 'VAT_IN', 'prices_include_vat' => true],
            'VAT_EXCLUSIVE' => ['tax_treatment' => 'VAT_IN', 'prices_include_vat' => false],
            default => ['tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false],
        };
        $lines = collect($this->input('lines', []))->map(function (mixed $line): mixed {
            if (! is_array($line)) {
                return $line;
            }
            $line['description'] = trim((string) ($line['description'] ?? ''));
            if (array_key_exists('line_amount', $line)) {
                return $line;
            }

            try {
                $line['line_amount'] = BigDecimal::of((string) ($line['quantity'] ?? ''))
                    ->multipliedBy(BigDecimal::of((string) ($line['unit_price'] ?? '')))
                    ->toScale(2, RoundingMode::HALF_UP)
                    ->__toString();
            } catch (\Throwable) {
                // Validation below reports the missing or malformed amount.
            }

            return $line;
        })->all();

        $this->merge([
            'tax_calculation' => $taxCalculation,
            'lines' => $lines,
            ...$tax,
        ]);
    }

    public function rules(): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();

        return ['supplier_id' => ['required', 'integer', 'min:1'], 'payment_term_id' => ['nullable', 'integer', 'min:1'], 'purchase_requisition_id' => ['nullable', 'integer', 'min:1'], 'warehouse_id' => ['nullable', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'], 'tax_calculation' => ['required', Rule::in(['VAT_INCLUSIVE', 'VAT_EXCLUSIVE', 'NONE'])], 'tax_treatment' => ['required', Rule::in(['NONE_VAT', 'VAT_IN'])], 'prices_include_vat' => ['required', 'boolean'], 'description' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.item_id' => ['nullable', 'integer', 'min:1'], 'lines.*.uom_id' => ['nullable', 'integer', 'min:1'], 'lines.*.tax_code_id' => ['nullable', 'integer', 'min:1', 'required_if:tax_treatment,VAT_IN'], 'lines.*.purchase_requisition_line_id' => ['nullable', 'integer', 'min:1'], 'lines.*.description' => ['nullable', 'string', 'max:500'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', $decimal], 'lines.*.line_amount' => ['required', 'numeric', 'min:0', 'decimal:0,2']];
    }
}
