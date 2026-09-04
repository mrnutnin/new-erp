<?php

namespace App\Modules\Purchasing\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();
        return ['supplier_id' => ['required', 'integer', 'min:1'], 'payment_term_id' => ['nullable', 'integer', 'min:1'], 'purchase_requisition_id' => ['nullable', 'integer', 'min:1'], 'warehouse_id' => ['nullable', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'], 'description' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.item_id' => ['nullable', 'integer', 'min:1'], 'lines.*.uom_id' => ['nullable', 'integer', 'min:1'], 'lines.*.purchase_requisition_line_id' => ['nullable', 'integer', 'min:1'], 'lines.*.description' => ['required', 'string', 'max:500'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', $decimal], 'lines.*.unit_price' => ['required', 'numeric', 'min:0', $decimal]];
    }
}
