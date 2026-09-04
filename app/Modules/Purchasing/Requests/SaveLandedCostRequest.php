<?php

namespace App\Modules\Purchasing\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveLandedCostRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'business_date' => ['required', 'date_format:Y-m-d'],
            'allocation_basis' => ['required', 'in:VALUE,QUANTITY,WEIGHT'],
            'receipt_ids' => ['required', 'array', 'min:1'],
            'receipt_ids.*' => ['integer', 'distinct', 'exists:goods_receipts,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'lines.*.description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
