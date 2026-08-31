<?php

namespace App\Modules\Wms\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveInventoryAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();

        return [
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'direction' => ['required', Rule::in(['GAIN', 'LOSS'])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            // Kept nullable for older API clients; the document header is the source of truth.
            'lines.*.direction' => ['nullable', Rule::in(['GAIN', 'LOSS'])],
            'lines.*.item_id' => ['required', 'integer', 'exists:wms_items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:wms_uoms,id'],
            'lines.*.quantity' => ['required', 'numeric', $decimal, 'gt:0', 'max:999999999999.99999999'],
            'lines.*.value' => ['required', 'numeric', $decimal, 'gt:0', 'max:999999999999.99999999'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Keep the old single-line POST contract usable while the UI moves to
        // document headers. Existing integrations can still submit the old
        // business_date/item_id fields and receive a one-line document.
        if (! $this->has('lines') && $this->filled('item_id')) {
            $this->merge([
                'document_date' => $this->input('document_date', $this->input('business_date')),
                'direction' => $this->input('direction'),
                'lines' => [[
                    'direction' => $this->input('direction'),
                    'item_id' => $this->input('item_id'),
                    'uom_id' => $this->input('uom_id'),
                    'quantity' => $this->input('quantity'),
                    'value' => $this->input('value'),
                ]],
            ]);
        }

        if (! $this->filled('direction') && $this->input('lines.0.direction')) {
            $this->merge(['direction' => $this->input('lines.0.direction')]);
        }
    }
}
