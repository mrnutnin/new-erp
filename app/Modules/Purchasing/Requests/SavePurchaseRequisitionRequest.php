<?php

namespace App\Modules\Purchasing\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseRequisitionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge(['description' => trim((string) $this->input('description')) ?: null, 'lines' => $this->input('lines', [])]);
    }

    public function rules(): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();
        return ['document_date' => ['required', 'date_format:Y-m-d'], 'supplier_id' => ['nullable', 'integer', 'min:1'], 'description' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.item_id' => ['required', 'integer', 'min:1'], 'lines.*.uom_id' => ['required', 'integer', 'min:1'], 'lines.*.quantity' => ['required', 'numeric', $decimal, 'gt:0', 'max:99999999999999.9999'], 'lines.*.description' => ['nullable', 'string', 'max:500']];
    }
}
