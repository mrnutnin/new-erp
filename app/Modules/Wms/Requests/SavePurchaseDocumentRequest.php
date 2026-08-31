<?php

namespace App\Modules\Wms\Requests;

use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Support\PurchaseDocumentCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePurchaseDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_type' => strtoupper(trim((string) $this->input('document_type'))),
            'tax_treatment' => strtoupper(trim((string) $this->input('tax_treatment', 'NONE_VAT'))),
            'prices_include_vat' => $this->boolean('prices_include_vat'),
            'description' => trim((string) $this->input('description')) ?: null,
            'original_document_id' => $this->filled('original_document_id') ? $this->input('original_document_id') : null,
            'payment_term_id' => $this->filled('payment_term_id') ? $this->input('payment_term_id') : null,
            'lines' => $this->input('lines', []),
        ]);
    }

    public function rules(): array
    {
        $decimalPlaces = (int) app(GlobalSettings::class)->value('tax_decimal_places');

        return [
            'document_type' => ['required', Rule::in(['INVOICE', 'CREDIT_NOTE'])],
            'purchase_mode' => ['required', Rule::in(['INVENTORY', 'EXPENSE'])],
            'original_document_id' => ['nullable', 'integer', 'min:1', 'required_if:document_type,CREDIT_NOTE', 'prohibited_if:document_type,INVOICE'],
            'document_date' => ['required', 'date_format:Y-m-d'],
            'tax_treatment' => ['required', Rule::in(['NONE_VAT', 'VAT_IN'])],
            'prices_include_vat' => ['required', 'boolean'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'payment_term_id' => ['nullable', 'integer', 'min:1', 'required_if:document_type,INVOICE'],
            'description' => ['nullable', 'string', 'max:500'],
            'withholding_tax_code_id' => ['nullable', 'integer', 'min:1'],
            'withholding_base' => ['nullable', 'numeric', 'decimal:0,2', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.item_id' => ['nullable', 'integer', 'min:1', 'required_with:lines.*.uom_id'],
            'lines.*.uom_id' => ['nullable', 'integer', 'min:1', 'required_with:lines.*.item_id'],
            'lines.*.account_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'numeric', "decimal:0,{$decimalPlaces}", 'gt:0', 'max:99999999999999.9999'],
            'lines.*.unit_price' => ['required', 'numeric', "decimal:0,{$decimalPlaces}", 'min:0', 'max:99999999999999.9999'],
            'lines.*.discount_amount' => ['required', 'numeric', "decimal:0,{$decimalPlaces}", 'min:0', 'max:9999999999999999.99'],
            'lines.*.tax_code_id' => ['nullable', 'integer', 'min:1', 'required_if:tax_treatment,VAT_IN'],
            'lines.*.purchase_order_line_id' => ['nullable', 'integer', 'min:1'],
            'lines.*.receipt_allocations' => ['nullable', 'array', 'max:20'],
            'lines.*.receipt_allocations.*.goods_receipt_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.receipt_allocations.*.allocated_quantity' => ['required', 'numeric', "decimal:0,{$decimalPlaces}", 'gt:0'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            try {
                $decimalPlaces = (int) app(GlobalSettings::class)->value('tax_decimal_places');
                PurchaseDocumentCalculator::calculate($this->input('lines'), 'NONE_VAT', false, $decimalPlaces, $decimalPlaces);
            } catch (\InvalidArgumentException $exception) {
                $validator->errors()->add('lines', $exception->getMessage());
            }

            $receiptLineIds = collect($this->input('lines', []))
                ->flatMap(fn (array $line) => collect($line['receipt_allocations'] ?? [])->pluck('goods_receipt_line_id'))
                ->filter()
                ->map(fn ($id) => (int) $id);
            if ($receiptLineIds->count() !== $receiptLineIds->unique()->count()) {
                $validator->errors()->add('lines', 'Goods Receipt line เดิมถูกเลือกซ้ำ กรุณาเลือกแต่ละรายการเพียงครั้งเดียว');
            }
        }];
    }
}
