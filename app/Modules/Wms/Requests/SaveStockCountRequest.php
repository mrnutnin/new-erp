<?php

namespace App\Modules\Wms\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;

final class SaveStockCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();

        return ['document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['nullable', 'string', 'max:500'], 'lines' => ['required', 'array', 'min:1', 'max:500'], 'lines.*.item_id' => ['required', 'integer', 'distinct', 'exists:wms_items,id'], 'lines.*.uom_id' => ['required', 'integer', 'exists:wms_uoms,id'], 'lines.*.counted_quantity' => ['required', 'numeric', $decimal, 'gte:0', 'max:999999999999.99999999'], 'lines.*.note' => ['nullable', 'string', 'max:500']];
    }
}
