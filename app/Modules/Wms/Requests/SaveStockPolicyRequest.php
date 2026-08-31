<?php

namespace App\Modules\Wms\Requests;

use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveStockPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $decimal = 'decimal:0,'.WmsDecimal::places();

        return ['item_id' => ['required', 'integer', Rule::exists('wms_items', 'id')->where(fn ($query) => $query->where('is_active', true)->where('is_stock_item', true))], 'min_quantity' => ['required', 'numeric', $decimal, 'min:0', 'max:999999999999.99999999'], 'max_quantity' => ['required', 'numeric', $decimal, 'min:0', 'max:999999999999.99999999'], 'reorder_quantity' => ['required', 'numeric', $decimal, 'min:0', 'max:999999999999.99999999'], 'is_active' => ['nullable', 'boolean']];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ((float) $this->input('max_quantity', 0) < (float) $this->input('min_quantity', 0)) {
                $validator->errors()->add('max_quantity', 'ค่าสูงสุดต้องไม่น้อยกว่าค่าต่ำสุด');
            }
        });
    }
}
