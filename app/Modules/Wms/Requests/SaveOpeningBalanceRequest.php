<?php

namespace App\Modules\Wms\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Modules\Wms\Support\WmsDecimal;

class SaveOpeningBalanceRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'cutover_date' => ['required', 'date'],
            'costing_method' => ['required', Rule::in(['AVG', 'FIFO'])],
            'source_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', 'exists:wms_items,id'],
            'lines.*.uom_id' => ['required', 'integer', 'exists:wms_uoms,id'],
            'lines.*.quantity' => ['required', 'numeric', $decimal, 'gt:0'],
            'lines.*.total_value' => ['required', 'numeric', $decimal, 'gte:0'],
        ];
    }
}
