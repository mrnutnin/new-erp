<?php

namespace App\Modules\Pos\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSalesDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))->map(function (mixed $line): mixed {
            if (is_array($line) && is_string($line['conversion_snapshot'] ?? null)) {
                $decoded = json_decode($line['conversion_snapshot'], true);
                $line['conversion_snapshot'] = is_array($decoded) ? $decoded : null;
            }
            if (is_array($line) && is_string($line['price_snapshot'] ?? null)) {
                $decoded = json_decode($line['price_snapshot'], true);
                $line['price_snapshot'] = is_array($decoded) ? $decoded : null;
            }

            return $line;
        })->all();
        $this->merge([
            'description' => trim((string) $this->input('description')) ?: null,
            'price_includes_vat' => $this->boolean('price_includes_vat'),
            'lines' => $this->missing('lines') ? [] : $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in(['INVOICE', 'CREDIT_NOTE'])],
            'source_invoice_id' => ['nullable', 'integer', 'required_if:document_type,CREDIT_NOTE'],
            'party_id' => ['required', 'integer'],
            'payment_term_id' => ['nullable', 'integer', 'required_if:document_type,INVOICE'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'due_date' => ['prohibited'],
            'price_includes_vat' => ['required', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
            'withholding_tax_code_id' => ['nullable', 'integer', 'min:1'],
            'withholding_base' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.item_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.uom_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.stock_uom_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.uom_factor' => ['nullable', 'numeric', 'decimal:0,8', 'gt:0'],
            'lines.*.conversion_snapshot' => ['nullable', 'array'],
            'lines.*.price_snapshot' => ['nullable', 'array'],
            'lines.*.quantity' => ['required', 'numeric', 'decimal:0,4', 'gt:0'],
            'lines.*.unit' => ['required', 'string', 'max:30'],
            'lines.*.unit_price' => ['required', 'numeric', 'decimal:0,4', 'min:0'],
            'lines.*.discount_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0'],
            'lines.*.revenue_account_id' => ['required', 'integer'],
            'lines.*.tax_code_id' => ['required', 'integer'],
        ];
    }
}
